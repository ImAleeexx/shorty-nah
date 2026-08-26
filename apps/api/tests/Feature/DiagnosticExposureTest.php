<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('returns no trace, path or configuration value when debug is disabled', function (): void {
    config()->set('app.debug', false);

    Route::get('/api/__throws', function (): never {
        throw new RuntimeException('database password is hunter2');
    });

    $response = $this->getJson('/api/__throws');

    $body = $response->getContent();

    expect($response->status())->toBe(500)
        ->and($body)->not->toContain('trace')
        ->and($body)->not->toContain('/app/')
        ->and($body)->not->toContain('hunter2')
        ->and($body)->not->toContain('.php');
});

it('reports the failure with debug enabled so developers still see it', function (): void {
    config()->set('app.debug', true);

    Route::get('/api/__throws2', function (): never {
        throw new RuntimeException('diagnostic detail');
    });

    expect($this->getJson('/api/__throws2')->getContent())->toContain('diagnostic detail');
});
