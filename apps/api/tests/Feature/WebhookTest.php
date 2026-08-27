<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Jobs\WebhookDeliveryFailed;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookService;
use App\Webhooks\WebhookSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function webhookOperator(): User
{
    return User::factory()->admin()->freshlyAuthenticated()->create();
}

function registerEndpoint(array $overrides = []): array
{
    $response = test()->actingAs(webhookOperator())->postJson('/api/v1/webhooks', array_merge([
        'name' => 'Warehouse',
        'url' => 'https://hooks.example.com/shortynah',
        'events' => ['click.recorded'],
    ], $overrides));

    return [$response, WebhookEndpoint::query()->latest('id')->first()];
}

beforeEach(function (): void {
    cache()->flush();
    Http::preventStrayRequests();
});

// --- 7.1 registration and the secret ---

it('shows the signing secret once and never again', function (): void {
    [$response, $endpoint] = registerEndpoint();

    $response->assertStatus(201);
    $secret = $response->json('secret');

    expect($secret)->toBeString()->not->toBeEmpty();

    $listed = $this->actingAs(webhookOperator())->getJson('/api/v1/webhooks')->assertOk()->json();

    expect(json_encode($listed))->not->toContain($secret);
});

it('stores the secret encrypted rather than in the clear', function (): void {
    [$response, $endpoint] = registerEndpoint();

    $secret = (string) $response->json('secret');

    $stored = DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

    // A signing secret cannot be hashed — an HMAC needs the value — so the
    // protection is encryption at rest. A database dump alone must not yield it.
    expect((string) $stored)->not->toBe($secret)
        ->and((string) $stored)->not->toContain($secret)
        ->and($endpoint->refresh()->secret)->toBe($secret);
});

// --- 7.2 refusing an endpoint ---

it('refuses an endpoint that is not https', function (): void {
    [$response] = registerEndpoint(['url' => 'http://hooks.example.com/x']);

    $response->assertStatus(422)->assertJsonValidationErrors('url');
});

it('refuses an endpoint resolving to a private or loopback address', function (): void {
    foreach (['https://127.0.0.1/hook', 'https://169.254.169.254/latest/meta-data'] as $url) {
        [$response] = registerEndpoint(['url' => $url]);

        $response->assertStatus(422)->assertJsonValidationErrors('url');
    }
});

it('refuses an unknown event', function (): void {
    [$response] = registerEndpoint(['events' => ['click.recorded', 'nonsense.happened']]);

    $response->assertStatus(422);
});

// --- 7.3 signing ---

it('signs a delivery so a receiver can verify it', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

    [$response, $endpoint] = registerEndpoint();
    $secret = (string) $response->json('secret');

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);

    Http::assertSent(function ($request) use ($secret): bool {
        $timestamp = (int) $request->header(WebhookSignature::TIMESTAMP_HEADER)[0];
        $signature = $request->header(WebhookSignature::HEADER)[0];

        // Recomputed exactly as a receiver would: the timestamp and the raw body.
        return $signature === WebhookSignature::compute($secret, $timestamp, $request->body());
    });
});

it('stops verifying with the previous secret once rotated', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

    [$response, $endpoint] = registerEndpoint();
    $original = (string) $response->json('secret');

    $rotated = (string) $this->actingAs(webhookOperator())
        ->postJson('/api/v1/webhooks/'.$endpoint->public_id.'/rotate')
        ->assertOk()->json('secret');

    expect($rotated)->not->toBe($original);

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);

    Http::assertSent(function ($request) use ($original, $rotated): bool {
        $timestamp = (int) $request->header(WebhookSignature::TIMESTAMP_HEADER)[0];
        $signature = $request->header(WebhookSignature::HEADER)[0];

        return $signature === WebhookSignature::compute($rotated, $timestamp, $request->body())
            && $signature !== WebhookSignature::compute($original, $timestamp, $request->body());
    });
});

// --- 7.4 / 7.5 delivery happens elsewhere ---

it('queues a delivery rather than making it inline', function (): void {
    Queue::fake();

    registerEndpoint();

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);

    Queue::assertPushed(DeliverWebhook::class);
});

it('puts deliveries on their own queue', function (): void {
    expect((new DeliverWebhook('x'))->queue)->toBe('webhooks');
});

// --- 7.6 / 7.7 retries ---

it('records an attempt and its status when an endpoint refuses', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('nope', 500)]);

    [, $endpoint] = registerEndpoint();

    // The job throws on a non-2xx so the queue retries it with the configured
    // backoff — without that, a refusal would be recorded once and never tried
    // again. Under the sync driver used in tests there is no queue to catch it,
    // so the throw surfaces here.
    try {
        app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);
        $threw = false;
    } catch (WebhookDeliveryFailed) {
        $threw = true;
    }

    $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();

    expect($threw)->toBeTrue()
        ->and($delivery->attempts)->toBeGreaterThan(0)
        ->and($delivery->last_status_code)->toBe(500)
        ->and($delivery->status)->not->toBe(WebhookDelivery::STATUS_DELIVERED);
});

it('marks a delivery delivered on success', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('', 202)]);

    registerEndpoint();

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);

    $delivery = WebhookDelivery::query()->latest('id')->firstOrFail();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->delivered_at)->not->toBeNull();
});

// --- 7.8 replay ---

it('records a replay separately from the original', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

    registerEndpoint();

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);

    $original = WebhookDelivery::query()->latest('id')->firstOrFail();

    $this->actingAs(webhookOperator())
        ->postJson('/api/v1/webhooks/deliveries/'.$original->public_id.'/replay')
        ->assertStatus(202);

    // The original is evidence an operator is looking at; a replay must not
    // overwrite it.
    expect(WebhookDelivery::query()->count())->toBe(2)
        ->and($original->refresh()->public_id)->toBe($original->public_id);
});

// --- 7.10 payloads carry no secrets ---

it('delivers no address, visitor hash, password or secret', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('', 200)]);

    [$response] = registerEndpoint();
    $secret = (string) $response->json('secret');

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, [
        'link_id' => 7,
        'country_code' => 'ES',
    ]);

    Http::assertSent(function ($request) use ($secret): bool {
        $body = $request->body();

        return ! str_contains($body, $secret)
            && ! str_contains($body, 'visitor_hash')
            && ! str_contains($body, 'address')
            && ! str_contains($body, 'password');
    });
});

// --- an endpoint that is disabled or unreachable ---

it('attempts nothing for a disabled endpoint', function (): void {
    Http::fake();

    [, $endpoint] = registerEndpoint();

    $this->actingAs(webhookOperator())
        ->patchJson('/api/v1/webhooks/'.$endpoint->public_id, ['disabled' => true])
        ->assertOk();

    app(WebhookService::class)->dispatch(WebhookEvent::ClickRecorded, ['link_id' => 7]);

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('hides every webhook route from an account that does not administrate', function (): void {
    [, $endpoint] = registerEndpoint();
    $member = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($member)->getJson('/api/v1/webhooks')->assertStatus(404);
    $this->actingAs($member)->postJson('/api/v1/webhooks', [
        'name' => 'x', 'url' => 'https://hooks.example.com/y', 'events' => ['click.recorded'],
    ])->assertStatus(404);
    $this->actingAs($member)->patchJson('/api/v1/webhooks/'.$endpoint->public_id, ['disabled' => true])->assertStatus(404);
});

// --- 7.12 audit ---

it('audits registration without recording the secret', function (): void {
    [$response, $endpoint] = registerEndpoint();

    $secret = (string) $response->json('secret');

    $entries = DB::table('audit_entries')->where('action', 'webhook.registered')->get();

    expect($entries)->toHaveCount(1)
        ->and(json_encode($entries))->not->toContain($secret);
});
