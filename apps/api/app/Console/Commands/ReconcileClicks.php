<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\ClickHouse\ClickHouseException;
use App\Links\ClickCounter;
use App\Providers\ClickHouseServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles click counts against the event store.
 *
 * The counter on the hot path is deliberately not durable — writing a row per
 * redirect would put a database on the fast path — so it drifts: envelopes can be
 * lost on a hard crash, and Redis can be flushed entirely.
 *
 * The event store is authoritative. Where it reports more counted clicks than the
 * counter holds, the counter is raised to match and the persisted total follows.
 * Counts only ever move forward: a lower figure means data was lost somewhere, not
 * that clicks were undone.
 */
final class ReconcileClicks extends Command
{
    protected $signature = 'shortynah:reconcile-clicks';

    protected $description = 'Persist Redis click counters into the links table';

    public function handle(ClickCounter $counter): int
    {
        $recorded = $this->recordedTotals();
        $reconciled = 0;

        DB::table('links')
            ->select(['id', 'click_count'])
            ->orderBy('id')
            ->chunkById(500, function (mixed $links) use ($counter, $recorded, &$reconciled): void {
                /** @var iterable<object{id: int, click_count: int}> $links */
                foreach ($links as $link) {
                    $id = (int) $link->id;

                    $authoritative = max(
                        $counter->current($id),
                        $recorded[$id] ?? 0,
                        (int) $link->click_count,
                    );

                    if ($authoritative === (int) $link->click_count && $authoritative === $counter->current($id)) {
                        continue;
                    }

                    if ($authoritative > $counter->current($id)) {
                        // The counter is behind what actually happened, so a
                        // limited link would otherwise start resolving again.
                        $counter->set($id, $authoritative);
                    }

                    if ($authoritative > (int) $link->click_count) {
                        DB::table('links')->where('id', $id)->update(['click_count' => $authoritative]);
                    }

                    $reconciled++;
                }
            });

        $this->components->info("Reconciled {$reconciled} link(s).");

        return self::SUCCESS;
    }

    /**
     * Counted clicks per link, straight from the rollups.
     *
     * Automated and duplicate events are excluded here for the same reason they
     * are excluded from every reported figure.
     *
     * @return array<int, int>
     */
    private function recordedTotals(): array
    {
        $connection = app(ClickHouseServiceProvider::READER);

        if (! $connection->ping()) {
            // Unreachable is not the same as zero. Returning nothing leaves the
            // counter and the persisted value as the best available answer.
            return [];
        }

        try {
            $rows = $connection->select('SELECT link_id, sum(counted) AS total FROM click_hourly GROUP BY link_id');
        } catch (ClickHouseException) {
            return [];
        }

        $totals = [];

        foreach ($rows as $row) {
            $linkId = (int) ($row['link_id'] ?? 0);

            if ($linkId > 0) {
                $totals[$linkId] = (int) ($row['total'] ?? 0);
            }
        }

        return $totals;
    }
}
