<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Links\ClickCounter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Persists the Redis click counters into the links table.
 *
 * The counter on the hot path is deliberately not durable — writing a row per
 * redirect would put a database on the fast path. This makes it durable
 * periodically instead, so a Redis flush cannot reopen a link that already
 * reached its limit.
 *
 * The authoritative total is the event store. Reconciling against that arrives
 * with the click pipeline; until events exist there is nothing more accurate than
 * the counter to reconcile from.
 */
final class ReconcileClicks extends Command
{
    protected $signature = 'shortynah:reconcile-clicks';

    protected $description = 'Persist Redis click counters into the links table';

    public function handle(ClickCounter $counter): int
    {
        $reconciled = 0;

        DB::table('links')
            ->select(['id', 'click_count'])
            ->orderBy('id')
            ->chunkById(500, function (mixed $links) use ($counter, &$reconciled): void {
                /** @var iterable<object{id: int, click_count: int}> $links */
                foreach ($links as $link) {
                    $counted = $counter->current((int) $link->id);

                    // Only ever moves forward: a counter behind the persisted
                    // value means Redis was flushed, not that clicks were undone.
                    if ($counted <= (int) $link->click_count) {
                        continue;
                    }

                    DB::table('links')->where('id', $link->id)->update(['click_count' => $counted]);
                    $reconciled++;
                }
            });

        $this->components->info("Reconciled {$reconciled} link(s).");

        return self::SUCCESS;
    }
}
