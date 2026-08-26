<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;

/**
 * The regression guard for Octane worker reuse: two full application boots in
 * one process must not share state.
 *
 * This lives in the unit suite deliberately. Constructing an application calls
 * Container::setInstance, so running it alongside database-backed feature tests
 * would hijack their container mid-transaction.
 */
it('carries no state across two application boots in one process', function (): void {
    $bootstrap = dirname(__DIR__, 2).'/bootstrap/app.php';

    $originalContainer = Container::getInstance();
    $originalFacadeApp = Facade::getFacadeApplication();

    try {
        $first = require $bootstrap;
        $first->instance('probe.value', 'set during the first boot');

        expect($first->bound('probe.value'))->toBeTrue();

        $second = require $bootstrap;

        expect($second)->not->toBe($first)
            ->and($second->bound('probe.value'))->toBeFalse();
    } finally {
        Container::setInstance($originalContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($originalFacadeApp);
    }
});
