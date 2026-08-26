<?php

declare(strict_types=1);

namespace App\Clicks;

use App\ClickHouse\ClickHouseException;
use App\ClickHouse\Connection;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * Writes a batch of enriched clicks to the event store.
 *
 * One request per batch, not per click: the whole reason clicks travel by list
 * rather than as individual jobs.
 */
final class ClickWriter
{
    public const TABLE = 'click_events';

    private const FAILURE_KEY = 'shortynah:clicks:last-write-failure';

    public function __construct(
        private readonly Connection $connection,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<EnrichedClick>  $clicks
     * @return int Rows written.
     */
    public function write(array $clicks): int
    {
        if ($clicks === []) {
            return 0;
        }

        $rows = array_map(static fn (EnrichedClick $click): array => $click->toRow(), $clicks);

        try {
            $written = $this->connection->insert(self::TABLE, $rows);
        } catch (ClickHouseException $e) {
            // Surfaced through logging and health rather than thrown: the redirect
            // that produced these events already succeeded, and there is nobody
            // left to return an error to.
            $this->cache->put(self::FAILURE_KEY, now()->toIso8601String(), 3600);

            $this->logger->error('Failed to write a batch of click events.', [
                'batch_size' => count($rows),
                'reason' => $e->getMessage(),
            ]);

            return 0;
        }

        $this->cache->forget(self::FAILURE_KEY);

        return $written;
    }

    public function lastFailureAt(): ?string
    {
        $at = $this->cache->get(self::FAILURE_KEY);

        return is_string($at) ? $at : null;
    }
}
