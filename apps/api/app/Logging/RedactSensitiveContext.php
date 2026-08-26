<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Channel tap that installs redaction on a channel's underlying Monolog
 * instance.
 */
final class RedactSensitiveContext
{
    public function __invoke(Logger $logger): void
    {
        $driver = $logger->getLogger();

        // Channels are not required to be Monolog-backed; a non-Monolog channel
        // has no processor stack to push onto.
        if (! $driver instanceof Monolog) {
            return;
        }

        $driver->pushProcessor(new RedactSensitiveValues);
    }
}
