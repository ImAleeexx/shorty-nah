<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Link;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * A request for a host this instance does not serve — never registered, or
 * registered and not yet verified — answers with a page that says so. Before
 * this, the bare host showed the framework's welcome page and a slug showed the
 * link-unavailable page, and neither told the operator what was wrong.
 */
function visitHost(string $host, string $path = '/'): TestResponse
{
    return test()->withServerVariables(['HTTP_HOST' => $host])->get("http://{$host}{$path}");
}

beforeEach(function (): void {
    RateLimiter::clear('redirect:127.0.0.1');
    cache()->flush();
});

it('explains an unregistered host on its bare address', function (): void {
    visitHost('stray.example.net')
        ->assertStatus(404)
        ->assertSee('isn’t set up', false)
        ->assertSee('stray.example.net')
        ->assertDontSee('Laravel');

    expect(visitHost('stray.example.net')->headers->get('Cache-Control'))->toContain('no-store');
});

it('explains an unregistered host on a slug', function (): void {
    visitHost('stray.example.net', '/launch')
        ->assertStatus(404)
        ->assertSee('isn’t set up', false)
        ->assertDontSee('This link isn’t available', false);
});

it('explains a registered host that is not yet verified', function (): void {
    $domain = Domain::factory()->unverified()->create(['host' => 'pending.example.com']);
    Link::factory()->forDomain($domain)->withSlug('launch')->create();

    visitHost('pending.example.com', '/launch')
        ->assertStatus(404)
        ->assertSee('isn’t set up', false)
        ->assertSee('pending.example.com');
});

it('answers the bare address of a verified domain as nothing to see', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);

    visitHost('go.example.com')
        ->assertStatus(404)
        ->assertSee('This link isn’t available', false)
        ->assertDontSee('isn’t set up', false)
        ->assertDontSee('Laravel');
});

it('keeps a missing slug on a verified domain indistinguishable from any other', function (): void {
    Domain::factory()->create(['host' => 'go.example.com']);

    visitHost('go.example.com', '/nothing')
        ->assertStatus(404)
        ->assertSee('This link isn’t available', false)
        ->assertDontSee('isn’t set up', false);
});

it('answers a repeated request for an unknown host without the database', function (): void {
    visitHost('stray.example.net', '/launch')->assertStatus(404);

    DB::enableQueryLog();
    DB::flushQueryLog();

    visitHost('stray.example.net', '/launch')->assertStatus(404);

    // The redirect path is the one route a stranger can drive at volume; a
    // wrong host must not turn it into a database query per request.
    expect(DB::getQueryLog())->toBeEmpty();
});
