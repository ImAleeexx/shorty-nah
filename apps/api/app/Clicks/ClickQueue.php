<?php

declare(strict_types=1);

namespace App\Clicks;

interface ClickQueue
{
    public function push(ClickEnvelope $envelope): void;

    /**
     * Removes and returns up to $max envelopes.
     *
     * @return list<ClickEnvelope>
     */
    public function drain(int $max): array;

    public function size(): int;

    public function clear(): void;
}
