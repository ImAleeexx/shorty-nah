<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

it('reports healthy when every dependency answers', function (): void {
    Http::fake(['*/ping' => Http::response('Ok.')]);

    $this->get('/up')->assertOk();
});

it('reports unhealthy when a dependency is unreachable', function (): void {
    // The framework's health route answers 200 as long as the process is up,
    // which reports healthy while the application cannot serve a request. This
    // asserts the listener that makes it mean something.
    Http::fake(['*/ping' => Http::response('', 500)]);

    $this->get('/up')->assertStatus(500);
});

it('names which dependency is unreachable', function (): void {
    Http::fake(['*/ping' => Http::response('', 500)]);

    // The name is the actionable part. What the probe must not carry is the
    // driver's own message, which routinely contains a DSN — that is asserted
    // where the probe is tested, and redaction generally in LogRedactionTest.
    $this->get('/up')->assertSee('clickhouse', escape: false);
});
