<?php

declare(strict_types=1);

namespace App\Clicks;

/**
 * An in-process queue for tests that exercise the pipeline without Redis.
 *
 * The Redis implementation is covered separately against a real server; this one
 * keeps enrichment tests fast and independent of a running service.
 */
final class ArrayClickQueue implements ClickQueue
{
    /** @var list<ClickEnvelope> */
    private array $envelopes = [];

    public function push(ClickEnvelope $envelope): void
    {
        $this->envelopes[] = $envelope;
    }

    /**
     * @return list<ClickEnvelope>
     */
    public function drain(int $max): array
    {
        $taken = array_slice($this->envelopes, 0, $max);
        $this->envelopes = array_slice($this->envelopes, count($taken));

        return $taken;
    }

    public function size(): int
    {
        return count($this->envelopes);
    }

    public function clear(): void
    {
        $this->envelopes = [];
    }
}
