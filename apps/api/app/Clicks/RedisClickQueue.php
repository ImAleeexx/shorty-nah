<?php

declare(strict_types=1);

namespace App\Clicks;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use JsonException;

/**
 * A Redis list, not a Laravel queue.
 *
 * The pipeline inserts in batches, and a job-per-click would mean an insert per
 * click — the opposite of what the event store wants. A list lets one drain take
 * hundreds of envelopes and write them in a single request.
 *
 * Losing envelopes on a hard crash is accepted: a lost click is a rounding error,
 * a failed redirect is a broken product.
 */
final class RedisClickQueue implements ClickQueue
{
    private const KEY = 'shortynah:clicks:pending';

    public function __construct(private readonly RedisFactory $redis) {}

    public function push(ClickEnvelope $envelope): void
    {
        try {
            $payload = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A single unencodable envelope must never break a redirect.
            return;
        }

        // Variadic, not an array. phpredis pushes an array argument as a single
        // serialised element, which makes every value unreadable on the way out.
        $this->redis->connection()->rpush(self::KEY, $payload);
    }

    /**
     * @return list<ClickEnvelope>
     */
    public function drain(int $max): array
    {
        $connection = $this->redis->connection();
        $envelopes = [];

        for ($i = 0; $i < $max; $i++) {
            $payload = $connection->lpop(self::KEY);

            if (! is_string($payload)) {
                break;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // Drop what cannot be read rather than stalling the queue on it.
                continue;
            }

            $envelopes[] = ClickEnvelope::fromArray($decoded);
        }

        return $envelopes;
    }

    public function size(): int
    {
        $length = $this->redis->connection()->llen(self::KEY);

        return is_numeric($length) ? (int) $length : 0;
    }

    public function clear(): void
    {
        $this->redis->connection()->del(self::KEY);
    }
}
