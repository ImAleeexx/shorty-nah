<?php

declare(strict_types=1);

use App\Clicks\ArrayClickQueue;
use App\Clicks\ClickEnvelope;
use App\Clicks\ClickQueue;
use App\Clicks\RedisClickQueue;
use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function queueHost(string $host = 'go.example.test'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

function fakeQueue(): ArrayClickQueue
{
    if (! app()->bound('test.queue')) {
        $queue = new ArrayClickQueue;
        app()->instance('test.queue', $queue);
        app()->instance(ClickQueue::class, $queue);
    }

    /** @var ArrayClickQueue $queue */
    $queue = app('test.queue');

    return $queue;
}

function request_slug(string $host, string $slug, array $headers = [], string $method = 'GET'): TestResponse
{
    $test = test()->withServerVariables(['HTTP_HOST' => $host]);

    return $method === 'HEAD'
        ? $test->head("http://{$host}/{$slug}", $headers)
        : $test->withHeaders($headers)->get("http://{$host}/{$slug}");
}

beforeEach(function (): void {
    cache()->flush();
    RateLimiter::clear('redirect:127.0.0.1');
    fakeQueue()->clear();
});

// --- 10.1 fire-and-forget enqueue ---

it('enqueues one envelope per redirect', function (): void {
    $domain = queueHost();
    $link = Link::factory()->forDomain($domain)->withSlug('enqueue1')->create([
        'destination' => 'https://example.org/x',
    ]);

    request_slug($domain->host, 'enqueue1')->assertStatus(302);

    $drained = fakeQueue()->drain(10);

    expect($drained)->toHaveCount(1)
        ->and($drained[0]->linkId)->toBe($link->id)
        ->and($drained[0]->domainId)->toBe($domain->id)
        ->and($drained[0]->redirectMode)->toBe('direct');
});

it('carries what the request resolved and never the address itself', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('rawenvl1')->create();

    request_slug($domain->host, 'rawenvl1', ['Referer' => 'https://news.example.org/story']);

    $envelope = fakeQueue()->drain(1)[0];

    // This test used to assert the opposite — that the envelope carried the raw
    // address, because the redirect did no enrichment at all. Geography now has
    // to be resolved during the request, since a country rule cannot be applied
    // after the visitor has already been sent somewhere; and once it has been,
    // carrying the address as well would put a raw address into Redis to answer
    // a question already answered.
    expect($envelope->address)->toBeNull()
        ->and($envelope->visitorHash)->not->toBeNull()
        ->and($envelope->geo)->not->toBeNull()
        ->and($envelope->referrer)->toBe('https://news.example.org/story')
        ->and($envelope->userAgent)->not->toBeNull()
        ->and(json_encode($envelope->toArray()))->not->toContain('127.0.0.1');
});

it('redirects successfully while the event store is unreachable', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('chdown12')->create([
        'destination' => 'https://example.org/still-works',
    ]);

    // Nothing on the hot path touches ClickHouse, so pointing it at a dead
    // address must change nothing.
    config()->set('clickhouse.host', '127.0.0.1');
    config()->set('clickhouse.port', 1);

    $started = microtime(true);

    request_slug($domain->host, 'chdown12')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.org/still-works');

    expect((microtime(true) - $started))->toBeLessThan(1.0);
});

it('enqueues a click for an interstitial view using the token identifier', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('interq12')->interstitial()->create();

    $response = request_slug($domain->host, 'interq12')->assertOk();

    $envelope = fakeQueue()->drain(1)[0];

    // The beacon reports against the token's click identifier, so the envelope
    // must use the same one or the signals would never meet the click.
    expect($envelope->redirectMode)->toBe('interstitial')
        ->and((string) $response->getContent())->toContain($envelope->clickId);
});

// --- 10.2 speculative requests ---

it('records nothing for a HEAD request', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('headreq1')->create();

    request_slug($domain->host, 'headreq1', [], 'HEAD');

    expect(fakeQueue()->size())->toBe(0);
});

it('records nothing for a prefetch', function (string $header, string $value): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('prefetc1')->create();

    request_slug($domain->host, 'prefetc1', [$header => $value])->assertStatus(302);

    // A browser looking ahead is not a person arriving.
    expect(fakeQueue()->size())->toBe(0);
})->with([
    ['Sec-Purpose', 'prefetch'],
    ['Sec-Purpose', 'prefetch;prerender'],
    ['Purpose', 'prefetch'],
    ['X-Purpose', 'preview'],
    ['X-Moz', 'prefetch'],
]);

it('still redirects a prefetch to the destination', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('prefetc2')->create([
        'destination' => 'https://example.org/target',
    ]);

    // Refusing to serve it would break the browser's optimisation; only the
    // counting is skipped.
    request_slug($domain->host, 'prefetc2', ['Sec-Purpose' => 'prefetch'])
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.org/target');
});

it('records an ordinary navigation', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('realnav1')->create();

    request_slug($domain->host, 'realnav1', [
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Dest' => 'document',
    ])->assertStatus(302);

    expect(fakeQueue()->size())->toBe(1);
});

// --- the Redis implementation, against a real server ---

it('round-trips envelopes through Redis', function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not reachable. Start the dev stack with `make up`.');
    }

    $queue = new RedisClickQueue(app('redis'));
    $queue->clear();

    for ($i = 0; $i < 250; $i++) {
        $queue->push(new ClickEnvelope(
            clickId: (string) Str::ulid(),
            linkId: $i,
            domainId: 1,
            occurredAt: '2026-08-26 12:00:00',
            address: '93.184.216.34',
            userAgent: 'probe',
            referrer: null,
            redirectMode: 'direct',
        ));
    }

    expect($queue->size())->toBe(250);

    $first = $queue->drain(100);
    $rest = $queue->drain(1000);

    expect($first)->toHaveCount(100)
        ->and($rest)->toHaveCount(150)
        ->and($queue->size())->toBe(0)
        // Order is preserved, so the oldest click is written first.
        ->and($first[0]->linkId)->toBe(0)
        ->and($rest[149]->linkId)->toBe(249);

    $queue->clear();
});

it('returns nothing from an empty Redis queue', function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis is not reachable.');
    }

    $queue = new RedisClickQueue(app('redis'));
    $queue->clear();

    expect($queue->drain(100))->toBe([])
        ->and($queue->size())->toBe(0);
});

it('redirects successfully while the click queue is unreachable', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('noqueue1')->create([
        'destination' => 'https://example.org/still-works',
    ]);

    // The real Redis-backed queue, pointed at a dead port. Recording a click must
    // never break the redirect that produced it: an unreachable queue costs one
    // click, a failed redirect costs the visitor.
    config()->set('database.redis.default.host', '127.0.0.1');
    config()->set('database.redis.default.port', 1);
    app()->forgetInstance('redis');
    app()->forgetInstance(ClickQueue::class);
    app()->bind(ClickQueue::class, RedisClickQueue::class);

    $started = microtime(true);

    request_slug($domain->host, 'noqueue1')
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.org/still-works');

    expect(microtime(true) - $started)->toBeLessThan(2.0);
});

it('serves an interstitial while the click queue is unreachable', function (): void {
    $domain = queueHost();
    Link::factory()->forDomain($domain)->withSlug('noqueue2')->interstitial()->create();

    config()->set('database.redis.default.host', '127.0.0.1');
    config()->set('database.redis.default.port', 1);
    app()->forgetInstance('redis');
    app()->forgetInstance(ClickQueue::class);
    app()->bind(ClickQueue::class, RedisClickQueue::class);

    request_slug($domain->host, 'noqueue2')->assertOk();
});
