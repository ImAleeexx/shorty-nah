<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;

function apiDomain(string $host = 'go.example.com'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

// --- 7.8 CRUD with authorization ---

it('creates a link', function (): void {
    apiDomain();
    $user = User::factory()->member()->create();

    $this->actingAs($user)->postJson('/api/v1/links', [
        'destination' => 'https://example.org/launch',
        'slug' => 'launch',
        'tags' => ['Spring', 'campaign'],
    ])->assertCreated()
        ->assertJsonPath('link.slug', 'launch')
        ->assertJsonPath('link.short_url', 'https://go.example.com/launch')
        ->assertJsonPath('link.tags', ['spring', 'campaign']);
});

it('surfaces a destination failure as a field error', function (): void {
    apiDomain();
    $user = User::factory()->member()->create();

    $this->actingAs($user)->postJson('/api/v1/links', ['destination' => 'javascript:alert(1)'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('destination');
});

it('refuses a viewer any write', function (): void {
    apiDomain();
    $viewer = User::factory()->viewer()->create();

    $this->actingAs($viewer)->postJson('/api/v1/links', ['destination' => 'https://example.org'])
        ->assertStatus(403);
});

it('hides another member link behind a 404', function (): void {
    apiDomain();
    $mine = User::factory()->member()->create();
    $theirs = User::factory()->member()->create();

    $link = Link::factory()->ownedBy($theirs)->create();

    // The member-cannot-see-others-links case named in 5.4, now that links exist.
    $forbidden = $this->actingAs($mine)->getJson("/api/v1/links/{$link->public_id}");
    $missing = $this->actingAs($mine)->getJson('/api/v1/links/01JQQQQQQQQQQQQQQQQQQQQQQQ');

    expect($forbidden->status())->toBe(404)
        ->and($forbidden->getContent())->toBe($missing->getContent());
});

it('lets an administrator read any link', function (): void {
    apiDomain();
    $admin = User::factory()->admin()->create();
    $link = Link::factory()->ownedBy(User::factory()->member()->create())->create();

    $this->actingAs($admin)->getJson("/api/v1/links/{$link->public_id}")
        ->assertOk()
        ->assertJsonPath('link.id', $link->public_id);
});

it('lists only the actor own links for a member', function (): void {
    apiDomain();
    $mine = User::factory()->member()->create();
    $theirs = User::factory()->member()->create();

    Link::factory()->count(2)->ownedBy($mine)->create();
    Link::factory()->count(3)->ownedBy($theirs)->create();

    $this->actingAs($mine)->getJson('/api/v1/links')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('lists every link for an administrator', function (): void {
    apiDomain();
    Link::factory()->count(4)->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->getJson('/api/v1/links')->assertOk()->assertJsonPath('meta.total', 4);
});

it('refuses to edit another member link', function (): void {
    apiDomain();
    $mine = User::factory()->member()->create();
    $link = Link::factory()->ownedBy(User::factory()->member()->create())->create();

    $this->actingAs($mine)->patchJson("/api/v1/links/{$link->public_id}", [
        'destination' => 'https://attacker.example.org',
    ])->assertStatus(404);

    expect($link->refresh()->destination)->not->toBe('https://attacker.example.org');
});

it('deletes a link softly so its analytics keep their context', function (): void {
    apiDomain();
    $user = User::factory()->member()->create();
    $link = Link::factory()->ownedBy($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/links/{$link->public_id}")->assertNoContent();

    expect(Link::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and(Link::withTrashed()->whereKey($link->id)->exists())->toBeTrue();
});

// --- 7.7 search and tags ---

it('searches by slug, destination and tag', function (string $term, int $expected): void {
    apiDomain();
    $user = User::factory()->admin()->create();

    $a = Link::factory()->ownedBy($user)->withSlug('spring24')->create(['destination' => 'https://shop.example.org/sale']);
    $b = Link::factory()->ownedBy($user)->withSlug('winter24')->create(['destination' => 'https://blog.example.org/post']);
    $a->tags()->attach(Tag::factory()->named('campaign')->create()->id);
    unset($b);

    $this->actingAs($user)->getJson('/api/v1/links?search='.urlencode($term))
        ->assertOk()
        ->assertJsonPath('meta.total', $expected);
})->with([
    ['spring', 1],
    ['24', 2],
    ['shop.example.org', 1],
    ['campaign', 1],
    ['nothing-matches', 0],
]);

it('filters by tag', function (): void {
    apiDomain();
    $user = User::factory()->admin()->create();
    $tagged = Link::factory()->ownedBy($user)->create();
    Link::factory()->ownedBy($user)->create();

    $tagged->tags()->attach(Tag::factory()->named('launch')->create()->id);

    $this->actingAs($user)->getJson('/api/v1/links?tag=Launch')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('scopes search to the actor role', function (): void {
    apiDomain();
    $mine = User::factory()->member()->create();
    $theirs = User::factory()->member()->create();

    Link::factory()->ownedBy($theirs)->withSlug('secret99')->create();

    // A search must not become a way to read someone else's links.
    $this->actingAs($mine)->getJson('/api/v1/links?search=secret')
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

it('normalises a tag so case does not create duplicates', function (): void {
    apiDomain();
    $user = User::factory()->member()->create();

    $this->actingAs($user)->postJson('/api/v1/links', [
        'destination' => 'https://example.org/a', 'slug' => 'first1', 'tags' => ['Launch'],
    ])->assertCreated();

    $this->actingAs($user)->postJson('/api/v1/links', [
        'destination' => 'https://example.org/b', 'slug' => 'second2', 'tags' => ['launch', 'LAUNCH'],
    ])->assertCreated();

    expect(Tag::query()->where('name', 'launch')->count())->toBe(1)
        ->and(Tag::query()->count())->toBe(1);
});

// --- 7.9 identifiers ---

it('never exposes the integer key of a link', function (): void {
    apiDomain();
    $user = User::factory()->admin()->create();
    Link::factory()->count(3)->ownedBy($user)->create();

    $body = $this->actingAs($user)->getJson('/api/v1/links')->getContent();

    expect($body)->not->toContain('"id":1')
        ->and($body)->not->toContain('"domain_id"')
        ->and($body)->not->toContain('"created_by"');

    foreach ($this->actingAs($user)->getJson('/api/v1/links')->json('links') as $link) {
        expect($link['id'])->toHaveLength(26);
    }
});

it('does not resolve a neighbouring identifier', function (): void {
    apiDomain();
    $user = User::factory()->admin()->create();
    $link = Link::factory()->ownedBy($user)->create();

    $walked = substr($link->public_id, 0, 25).'z';

    $this->actingAs($user)->getJson("/api/v1/links/{$walked}")->assertStatus(404);
});

it('never exposes a link password hash', function (): void {
    apiDomain();
    $user = User::factory()->member()->create();
    $link = Link::factory()->ownedBy($user)->passwordProtected()->create();

    $body = $this->actingAs($user)->getJson("/api/v1/links/{$link->public_id}")->getContent();

    expect($body)->not->toContain('password_hash')
        ->and($body)->not->toContain('$argon2id$')
        ->and(json_decode($body, true)['link']['password_protected'])->toBeTrue();
});
