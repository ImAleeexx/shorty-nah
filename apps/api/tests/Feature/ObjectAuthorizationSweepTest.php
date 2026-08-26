<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Domain;
use App\Models\Invitation;
use App\Models\Link;
use App\Models\User;

/**
 * The IDOR sweep.
 *
 * Every endpoint that names an object is asked the same three questions: can a
 * stranger read it, can a stranger reach something nested under it, and can a
 * client widen its own scope by saying so. The answer to an unauthorized read is
 * always 404 — a 403 confirms the object exists, which is the disclosure the
 * whole rule exists to prevent.
 */
function sweepOwnerWithLink(): array
{
    $owner = User::factory()->create(['role' => Role::Member]);
    $domain = Domain::factory()->create(['verified_at' => now()]);
    $link = Link::factory()->create(['created_by' => $owner->id, 'domain_id' => $domain->id]);

    return [$owner, $link, $domain];
}

function sweepStranger(): User
{
    return User::factory()->freshlyAuthenticated()->create(['role' => Role::Member]);
}

// --- Links, and everything nested under one ---

it('answers 404 for every read of another member link', function (string $path): void {
    [, $link] = sweepOwnerWithLink();

    $this->actingAs(sweepStranger())
        ->getJson(str_replace('{id}', $link->public_id, $path))
        ->assertStatus(404);
})->with([
    '/api/v1/links/{id}',
    '/api/v1/links/{id}/report',
    '/api/v1/links/{id}/events',
    '/api/v1/links/{id}/export',
]);

it('answers 404 identically for a forbidden link and one that never existed', function (): void {
    [, $link] = sweepOwnerWithLink();
    $stranger = sweepStranger();

    $forbidden = $this->actingAs($stranger)->getJson("/api/v1/links/{$link->public_id}");
    $missing = $this->actingAs($stranger)->getJson('/api/v1/links/01JQZZZZZZZZZZZZZZZZZZZZZZ');

    expect($forbidden->status())->toBe($missing->status())
        ->and($forbidden->getContent())->toBe($missing->getContent());
});

it('refuses to write another member link', function (): void {
    [, $link] = sweepOwnerWithLink();

    $this->actingAs(sweepStranger())
        ->patchJson("/api/v1/links/{$link->public_id}", ['destination' => 'https://example.com/taken'])
        ->assertStatus(404);

    $this->actingAs(sweepStranger())
        ->deleteJson("/api/v1/links/{$link->public_id}")
        ->assertStatus(404);
});

it('ignores a client-supplied owner when listing links', function (): void {
    [$owner] = sweepOwnerWithLink();
    $stranger = sweepStranger();

    // Naming the other account's identifier must not widen the caller's scope.
    $response = $this->actingAs($stranger)
        ->getJson("/api/v1/links?owner={$owner->public_id}")
        ->assertOk();

    expect($response->json('links'))->toBe([]);
});

it('derives ownership from the session rather than the payload on create', function (): void {
    $stranger = sweepStranger();
    $other = User::factory()->create(['role' => Role::Member]);
    $domain = Domain::factory()->create(['verified_at' => now(), 'is_primary' => true]);

    $this->actingAs($stranger)
        ->postJson('/api/v1/links', [
            'destination' => 'https://example.com/mine',
            // Both of these are attempts to claim someone else's scope.
            'created_by' => $other->id,
            'owner' => $other->public_id,
        ])
        ->assertStatus(201);

    $link = Link::query()->latest('id')->firstOrFail();

    expect($link->created_by)->toBe($stranger->id)
        ->and($link->domain_id)->toBe($domain->id);
});

// --- Users ---

it('answers 404 when a member reads another account', function (): void {
    $stranger = sweepStranger();
    $other = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($stranger)->getJson("/api/v1/users/{$other->public_id}")->assertStatus(404);
    $this->actingAs($stranger)->getJson('/api/v1/users')->assertStatus(404);
});

it('refuses a member changing anyone role, including upward', function (): void {
    $stranger = sweepStranger();
    $other = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($stranger)
        ->patchJson("/api/v1/users/{$other->public_id}/role", ['role' => 'owner'])
        ->assertStatus(404);

    expect($other->refresh()->role)->toBe(Role::Member);
});

it('refuses an administrator granting a role above its own', function (): void {
    $admin = User::factory()->freshlyAuthenticated()->create(['role' => Role::Admin]);
    $target = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'owner'])
        ->assertStatus(403);

    expect($target->refresh()->role)->toBe(Role::Member);
});

// --- Domains, invitations, tokens ---

it('hides domain, invitation and token management from a member', function (string $path): void {
    $this->actingAs(sweepStranger())->getJson($path)->assertStatus(404);
})->with([
    '/api/v1/invitations',
]);

it('refuses a member registering or deleting a domain', function (): void {
    $stranger = sweepStranger();
    $domain = Domain::factory()->create(['verified_at' => now()]);

    $this->actingAs($stranger)
        ->postJson('/api/v1/domains', ['host' => 'sneaky.example.test'])
        ->assertStatus(404);

    $this->actingAs($stranger)
        ->deleteJson("/api/v1/domains/{$domain->public_id}")
        ->assertStatus(404);
});

it('refuses revoking another account invitation', function (): void {
    $admin = User::factory()->freshlyAuthenticated()->create(['role' => Role::Admin]);
    $stranger = sweepStranger();

    $issued = $this->actingAs($admin)
        ->postJson('/api/v1/invitations', ['email' => 'someone@example.test', 'role' => 'member'])
        ->assertStatus(201);

    $invitation = Invitation::query()->where('public_id', $issued->json('id'))->firstOrFail();

    $this->actingAs($stranger)
        ->deleteJson("/api/v1/invitations/{$invitation->public_id}")
        ->assertStatus(404);

    expect($invitation->refresh()->revoked_at)->toBeNull();
});

it('refuses revoking a token belonging to someone else', function (): void {
    $owner = User::factory()->freshlyAuthenticated()->create();
    $stranger = sweepStranger();

    $created = $this->actingAs($owner)
        ->postJson('/api/v1/tokens', ['name' => 'theirs', 'abilities' => ['links:read']])
        ->assertStatus(201);

    $this->actingAs($stranger)
        ->deleteJson("/api/v1/tokens/{$created->json('id')}")
        ->assertStatus(404);
});

// --- Settings and branding ---

it('hides the configuration surfaces from a member', function (string $path): void {
    $this->actingAs(sweepStranger())->getJson($path)->assertStatus(404);
})->with([
    '/api/v1/settings',
    '/api/v1/branding',
]);

it('refuses a member writing configuration', function (): void {
    $stranger = sweepStranger();

    $this->actingAs($stranger)
        ->putJson('/api/v1/settings', ['settings' => ['registration.mode' => 'open']])
        ->assertStatus(404);

    $this->actingAs($stranger)
        ->putJson('/api/v1/branding', ['name' => 'Taken over'])
        ->assertStatus(404);
});

it('ignores a claimed role in the request body', function (): void {
    $stranger = sweepStranger();

    // The decision uses the stored role, so saying otherwise changes nothing.
    $this->actingAs($stranger)
        ->getJson('/api/v1/settings?role=owner')
        ->assertStatus(404);

    $this->actingAs($stranger)
        ->putJson('/api/v1/settings', [
            'role' => 'owner',
            'settings' => ['registration.mode' => 'open'],
        ])
        ->assertStatus(404);
});
