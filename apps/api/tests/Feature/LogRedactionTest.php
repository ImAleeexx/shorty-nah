<?php

declare(strict_types=1);

use App\Logging\RedactSensitiveContext;
use App\Logging\RedactSensitiveValues;
use Illuminate\Support\Facades\Log;

it('redacts secrets in what the configured channel actually writes', function (): void {
    $path = storage_path('logs/redaction-probe.log');

    if (file_exists($path)) {
        unlink($path);
    }

    config()->set('logging.channels.probe', [
        'driver' => 'single',
        'path' => $path,
        'level' => 'debug',
        'tap' => [RedactSensitiveContext::class],
    ]);

    Log::channel('probe')->error('authentication failed', [
        'email' => 'operator@example.test',
        'password' => 'hunter2',
        'ip' => '203.0.113.7',
        'headers' => ['authorization' => 'Bearer secret-token'],
    ]);

    $written = file_get_contents($path);
    unlink($path);

    expect($written)->not->toContain('hunter2')
        ->and($written)->not->toContain('203.0.113.7')
        ->and($written)->not->toContain('secret-token')
        ->and($written)->toContain(RedactSensitiveValues::REDACTED)
        ->and($written)->toContain('operator@example.test');
});
