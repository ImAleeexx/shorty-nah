<?php

declare(strict_types=1);

use App\Links\ClickCounter;
use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

const LINK_PASSWORD = 'a quiet lantern drifts';

function protectedHost(string $host = 'go.example.com'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

function open(string $host, string $slug): TestResponse
{
    return test()->withServerVariables(['HTTP_HOST' => $host])->get("http://{$host}/{$slug}");
}

function submit(string $host, string $slug, string $password): TestResponse
{
    return test()->withServerVariables(['HTTP_HOST' => $host])
        ->post("http://{$host}/{$slug}", ['password' => $password]);
}

beforeEach(function (): void {
    cache()->flush();
    RateLimiter::clear('redirect:127.0.0.1');
});

it('prompts for a password without revealing the destination', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('locked12')->passwordProtected(LINK_PASSWORD)->create([
        'destination' => 'https://confidential.example.org/deck',
    ]);

    $response = open($domain->host, 'locked12');

    expect($response->status())->toBe(401)
        ->and($response->getContent())->not->toContain('confidential.example.org')
        ->and($response->headers->get('Location'))->toBeNull()
        ->and($response->getContent())->toContain('Password');
});

it('discloses nothing about the link beyond the prompt', function (): void {
    $domain = protectedHost();
    $link = Link::factory()->forDomain($domain)->withSlug('locked34')->passwordProtected(LINK_PASSWORD)->create();

    $body = (string) open($domain->host, 'locked34')->getContent();

    // Asserting the integer key is absent is not workable — it is "1" here, and
    // a single digit appears in any HTML document. The public identifier and the
    // hash are the values that would actually matter.
    expect($body)->not->toContain($link->public_id)
        ->and($body)->not->toContain('argon2id')
        ->and($body)->not->toContain($link->destination);
});

it('redirects once the correct password is given', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('unlock12')->passwordProtected(LINK_PASSWORD)->create([
        'destination' => 'https://example.org/inside',
    ]);

    submit($domain->host, 'unlock12', LINK_PASSWORD)
        ->assertStatus(302)
        ->assertHeader('Location', 'https://example.org/inside');
});

it('re-prompts on an incorrect password', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('wrongpw1')->passwordProtected(LINK_PASSWORD)->create();

    $response = submit($domain->host, 'wrongpw1', 'not-the-password');

    expect($response->status())->toBe(401)
        ->and($response->getContent())->toContain('incorrect')
        ->and($response->headers->get('Location'))->toBeNull();
});

it('counts a click only after the password succeeds', function (): void {
    $domain = protectedHost();
    $link = Link::factory()->forDomain($domain)->withSlug('countpw1')->passwordProtected(LINK_PASSWORD)->create();

    $counter = app(ClickCounter::class);

    open($domain->host, 'countpw1');
    submit($domain->host, 'countpw1', 'wrong');

    expect($counter->current($link->id))->toBe(0);

    submit($domain->host, 'countpw1', LINK_PASSWORD)->assertStatus(302);

    expect($counter->current($link->id))->toBe(1);
});

it('rate limits password attempts per source and link', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('bruteforce')->passwordProtected(LINK_PASSWORD)->create();

    for ($attempt = 0; $attempt < 8; $attempt++) {
        submit($domain->host, 'bruteforce', "guess-{$attempt}")->assertStatus(401);
    }

    $blocked = submit($domain->host, 'bruteforce', 'guess-9');

    expect($blocked->status())->toBe(429)
        ->and($blocked->getContent())->toContain('Too many attempts');
});

it('still refuses the correct password while rate limited', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('locked56')->passwordProtected(LINK_PASSWORD)->create();

    for ($attempt = 0; $attempt < 8; $attempt++) {
        submit($domain->host, 'locked56', 'wrong');
    }

    // A limiter that let the right password through would be trivially bypassed
    // by guessing until the correct one happened to land.
    submit($domain->host, 'locked56', LINK_PASSWORD)->assertStatus(429);
});

it('clears the limiter after a successful unlock', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('locked78')->passwordProtected(LINK_PASSWORD)->create();

    submit($domain->host, 'locked78', 'wrong')->assertStatus(401);
    submit($domain->host, 'locked78', LINK_PASSWORD)->assertStatus(302);

    expect(RateLimiter::attempts('link-password:'.Link::query()->where('slug', 'locked78')->firstOrFail()->public_id.':'.sha1('127.0.0.1')))
        ->toBe(0);
});

it('does not unlock an expired protected link', function (): void {
    $domain = protectedHost();
    Link::factory()->forDomain($domain)->withSlug('lockedex')->passwordProtected(LINK_PASSWORD)->expired()->create();

    submit($domain->host, 'lockedex', LINK_PASSWORD)->assertStatus(404);
});

it('does not leak whether a slug exists through the unlock route', function (): void {
    $domain = protectedHost();

    $missing = submit($domain->host, 'nothere1', 'anything');
    Link::factory()->forDomain($domain)->withSlug('disabld9')->disabled()->passwordProtected(LINK_PASSWORD)->create();
    $disabled = submit($domain->host, 'disabld9', 'anything');

    expect($missing->status())->toBe($disabled->status())
        ->and($missing->getContent())->toBe($disabled->getContent());
});
