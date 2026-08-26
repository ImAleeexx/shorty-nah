<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Clicks\ClickWriter;
use App\Models\Link;
use App\Providers\ClickHouseServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Bulk click events for the drill-down browser test.
 *
 * Rows are inserted straight into the event store rather than pushed through the
 * pipeline: the redirect is rate limited per address, so several thousand events
 * cannot be produced from one host, and the pipeline already has its own tests.
 * What this fixture exists to exercise is the virtualized table above it.
 */
final class SeedEndToEndClicks extends Command
{
    protected $signature = 'shortynah:e2e-clicks
        {--slug='.SeedEndToEndFixture::DIRECT_SLUG.' : Slug to attach the events to}
        {--count=3000 : How many events to insert}';

    protected $description = 'Insert synthetic click events for the drill-down browser suite';

    public function handle(): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->components->error('This fixture is for development only.');

            return self::FAILURE;
        }

        $slug = (string) $this->option('slug');
        $count = max(1, (int) $this->option('count'));

        $link = Link::query()->where('slug', $slug)->first();

        if (! $link instanceof Link) {
            $this->components->error("No link with slug [{$slug}]. Run make e2e-fixture first.");

            return self::FAILURE;
        }

        $countries = ['GB', 'US', 'DE', 'FR', 'ES', 'NL', 'PL', 'IE'];
        $browsers = ['Chrome', 'Firefox', 'Safari', 'Edge'];
        $systems = ['macOS', 'Windows', 'Linux', 'iOS', 'Android'];
        $devices = ['desktop', 'smartphone', 'tablet'];
        $referrers = ['news.example.com', 'social.example.com', 'mail.example.com', ''];

        $start = Carbon::now()->subDays(13);
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'click_id' => (string) Str::ulid(),
                'link_id' => $link->id,
                'domain_id' => $link->domain_id,
                // Spread across the reporting window so the series has shape
                // rather than one enormous bucket.
                'occurred_at' => $start->copy()->addMinutes((int) ($i * (13 * 1440 / $count)))->format('Y-m-d H:i:s'),
                'visitor_hash' => hash('sha256', "e2e-{$i}"),
                'is_automated' => 0,
                'automated_reason' => '',
                'is_duplicate' => 0,
                'country_code' => $countries[$i % count($countries)],
                'region' => '',
                'city' => '',
                'asn' => 0,
                'as_organisation' => '',
                'device_type' => $devices[$i % count($devices)],
                'operating_system' => $systems[$i % count($systems)],
                'browser' => $browsers[$i % count($browsers)],
                'referrer_host' => $referrers[$i % count($referrers)],
                'redirect_mode' => 'direct',
            ];
        }

        // Resolved by name rather than by type: the bare Connection binding is
        // the read-only identity, and this writes.
        $connection = app(ClickHouseServiceProvider::WRITER);

        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->insert(ClickWriter::TABLE, $chunk);
        }

        $this->components->info("Inserted {$count} click events for /{$slug}.");

        return self::SUCCESS;
    }
}
