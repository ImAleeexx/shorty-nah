<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Clicks\ClickEnricher;
use App\Clicks\ClickQueue;
use App\Clicks\ClickWriter;
use App\Enums\WebhookEvent;
use App\Links\ClickCounter;
use App\Webhooks\WebhookService;
use Illuminate\Console\Command;

/**
 * Drains pending click envelopes, enriches them, and writes one batch.
 *
 * Runs continuously under the queue worker and on a schedule as a backstop, so a
 * worker restart cannot leave envelopes sitting indefinitely.
 */
final class DrainClicks extends Command
{
    protected $signature = 'shortynah:drain-clicks
        {--batch=500 : Envelopes to take in one pass}
        {--passes=1 : How many batches to drain before returning}
        {--daemon : Keep running, waiting when the queue is empty}
        {--sleep=1 : Seconds to wait between empty passes in daemon mode}';

    protected $description = 'Enrich pending click envelopes and write them to the event store';

    private bool $shuttingDown = false;

    /**
     * Finishes the batch in hand before exiting, so a deploy does not discard
     * envelopes that were already taken off the queue.
     */
    private function listenForShutdown(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shuttingDown = true;
            });
        }
    }

    public function handle(
        ClickQueue $queue,
        ClickEnricher $enricher,
        ClickWriter $writer,
        WebhookService $webhooks,
        ClickCounter $counter,
    ): int {
        $batch = max(1, (int) $this->option('batch'));
        $passes = max(1, (int) $this->option('passes'));
        $daemon = (bool) $this->option('daemon');
        $sleep = max(1, (int) $this->option('sleep'));

        $this->listenForShutdown();

        $written = 0;
        $counted = 0;

        for ($pass = 0; $daemon || $pass < $passes; $pass++) {
            if ($this->shuttingDown) {
                break;
            }

            $envelopes = $queue->drain($batch);

            if ($envelopes === []) {
                if (! $daemon) {
                    break;
                }

                // Waiting rather than returning: a daemon that exits on an empty
                // queue is a daemon that restart-loops.
                sleep($sleep);

                continue;
            }

            $enriched = $enricher->enrichAll($envelopes);

            $written += $writer->write($enriched);

            foreach ($enriched as $click) {
                if ($click->isCounted()) {
                    $counted++;

                    // Fired here rather than from the redirect, deliberately: a
                    // webhook dispatched on the hot path would put an operator's
                    // endpoint between a visitor and their destination. Only
                    // counted clicks are delivered — a bot or a duplicate is not
                    // an event anyone asked to hear about.
                    $webhooks->dispatch(WebhookEvent::ClickRecorded, $click->toWebhookPayload());
                }
            }

            if (! $daemon) {
                continue;
            }

            // Reported per batch in daemon mode; a long-lived process that only
            // reports at the end reports nothing useful.
            $this->line("  drained {$written} event(s), {$counted} counted");
            $written = 0;
            $counted = 0;
        }

        if (! $daemon && ($written > 0 || $counted > 0)) {
            $this->components->info("Wrote {$written} event(s), {$counted} counted.");
        }

        return self::SUCCESS;
    }
}
