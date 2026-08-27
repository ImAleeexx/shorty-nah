<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Measures the redirect hot path against a cached link.
 *
 * The point is comparison, not an absolute figure: a number from this machine
 * means nothing next to a number from another one, and everything next to a
 * number from this machine an hour ago. So a run can be recorded as a baseline
 * and a later run compared against it with a budget for the difference.
 *
 * Requests go through the HTTP kernel rather than the controller directly. The
 * throttle middleware, the route match and the response headers are all part of
 * what a visitor waits for, and measuring the controller alone would flatter the
 * result by excluding them.
 */
final class BenchmarkRedirect extends Command
{
    protected $signature = 'shortynah:bench-redirect
        {--iterations=2000 : Measured requests}
        {--warmup=200 : Requests before measuring, to fill the cache and the opcode cache}
        {--record= : Write the result to this file as the baseline}
        {--compare= : Compare against a recorded baseline}
        {--budget-us=0 : Permitted increase in mean microseconds over the baseline}';

    protected $description = 'Measure the redirect hot path against a cached link';

    private const SLUG = 'benchmk1';

    public function handle(Kernel $kernel): int
    {
        if ($this->getLaravel()->isProduction()) {
            $this->components->error('This benchmark seeds a link and is for development only.');

            return self::FAILURE;
        }

        $host = $this->prepare();

        foreach (self::PREFIXES as $prefix) {
            for ($host_octet = 10; $host_octet < 42; $host_octet++) {
                $this->sources[] = $prefix.'.'.$host_octet;
            }
        }

        $iterations = max(1, (int) $this->option('iterations'));
        $warmup = max(0, (int) $this->option('warmup'));

        for ($i = 0; $i < $warmup; $i++) {
            $this->fire($kernel, $host);
        }

        $samples = [];

        for ($i = 0; $i < $iterations; $i++) {
            $started = hrtime(true);
            $status = $this->fire($kernel, $host);
            $samples[] = (hrtime(true) - $started) / 1000;

            if ($status !== 302) {
                $this->components->error("The benchmark link answered {$status}, not 302. Nothing was measured.");

                return self::FAILURE;
            }
        }

        sort($samples);

        $result = [
            'iterations' => $iterations,
            'mean_us' => round(array_sum($samples) / $iterations, 2),
            'p50_us' => round($this->percentile($samples, 0.50), 2),
            'p95_us' => round($this->percentile($samples, 0.95), 2),
            'p99_us' => round($this->percentile($samples, 0.99), 2),
        ];

        $this->table(array_keys($result), [array_map(strval(...), $result)]);

        $record = $this->option('record');

        if (is_string($record) && $record !== '') {
            File::ensureDirectoryExists(dirname($record));
            File::put($record, json_encode($result, JSON_PRETTY_PRINT).PHP_EOL);
            $this->components->info("Recorded as the baseline in {$record}.");
        }

        $compare = $this->option('compare');

        if (is_string($compare) && $compare !== '') {
            return $this->compare($compare, $result['mean_us']);
        }

        return self::SUCCESS;
    }

    private function compare(string $path, float $mean): int
    {
        if (! File::exists($path)) {
            $this->components->error("No baseline at {$path}. Record one first.");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $baseline */
        $baseline = json_decode((string) File::get($path), true);
        $before = is_numeric($baseline['mean_us'] ?? null) ? (float) $baseline['mean_us'] : 0.0;
        $budget = (float) $this->option('budget-us');
        $added = round($mean - $before, 2);

        $this->line(sprintf(
            '  baseline %.2fus, now %.2fus, added %+.2fus, budget %.2fus',
            $before,
            $mean,
            $added,
            $budget,
        ));

        if ($added > $budget) {
            $this->components->error(sprintf(
                'The redirect path is %.2fus slower than the baseline, which exceeds the %.2fus budget.',
                $added,
                $budget,
            ));

            return self::FAILURE;
        }

        $this->components->info('Inside the budget.');

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $fraction): float
    {
        $index = (int) floor($fraction * (count($sorted) - 1));

        return $sorted[$index];
    }

    /**
     * A spread of real public addresses rather than one repeated.
     *
     * Two reasons. The redirect carries a per-source limit of 240 a minute, so a
     * benchmark hammering one address ends up measuring the limiter refusing it —
     * and consecutive runs inside one minute accumulate against the same
     * addresses, which is how a "pass" turns out to have been a 429. And once
     * geography is resolved on this path, only addresses that actually appear in
     * the MaxMind database measure a real lookup rather than an early miss.
     *
     * Thirty-two hosts in each of eight real public prefixes: enough that a run
     * of a few thousand requests puts single digits on any one address.
     *
     * @var list<string>
     */
    private const PREFIXES = [
        '8.8.8', '1.1.1', '142.250.185', '104.16.132',
        '80.58.61', '212.230.1', '213.4.129', '151.101.1',
    ];

    /** @var list<string> */
    private array $sources = [];

    private int $source = 0;

    private function fire(Kernel $kernel, string $host): int
    {
        $address = $this->sources[$this->source % count($this->sources)];
        $this->source++;

        $request = Request::create('http://'.$host.'/'.self::SLUG, 'GET', server: ['REMOTE_ADDR' => $address]);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/140.0 Safari/537.36');
        $request->headers->set('Accept-Language', 'en-GB,en;q=0.9');

        $response = $kernel->handle($request);

        return $response->getStatusCode();
    }

    /**
     * Seeds a link the benchmark owns, on the first verified domain. Written with
     * forceFill because the destination validator refuses anything that does not
     * resolve publicly, and a benchmark must not depend on the network.
     */
    private function prepare(): string
    {
        $domain = Domain::query()->whereNotNull('verified_at')->orderBy('id')->first();

        if (! $domain instanceof Domain) {
            $this->components->error('No verified domain. Run make e2e-fixture first.');

            exit(self::FAILURE);
        }

        $link = Link::query()->where('domain_id', $domain->id)->where('slug', self::SLUG)->first();

        if (! $link instanceof Link) {
            $link = new Link;
            $link->forceFill([
                'public_id' => (string) \Illuminate\Support\Str::ulid(),
                'domain_id' => $domain->id,
                'slug' => self::SLUG,
                'destination' => 'https://example.com/benchmark',
                'redirect_mode' => 'direct',
                'click_count' => 0,
            ])->save();
        }

        return (string) $domain->host;
    }
}
