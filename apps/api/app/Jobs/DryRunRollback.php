<?php

declare(strict_types=1);

namespace App\Jobs;

use RuntimeException;

/**
 * Thrown to unwind a rehearsed row's transaction after it has been fully
 * exercised. Not an error: it carries the slug the row would have taken.
 */
final class DryRunRollback extends RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct('Rehearsed.');
    }
}
