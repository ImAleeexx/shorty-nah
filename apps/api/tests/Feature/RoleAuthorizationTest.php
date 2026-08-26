<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

it('answers 404 rather than 403 when a member reads another account', function (): void {
    $member = User::factory()->member()->freshlyAuthenticated()->create();
    $other = User::factory()->member()->create();

    // A 403 would confirm the account exists. The response must be
    // indistinguishable from one for an identifier that was never issued.
    $forbidden = $this->actingAs($member)->getJson("/api/v1/users/{$other->public_id}");
    $missing = $this->actingAs($member)->getJson('/api/v1/users/01JQQQQQQQQQQQQQQQQQQQQQQQ');

    expect($forbidden->status())->toBe(404)
        ->and($forbidden->status())->toBe($missing->status())
        ->and($forbidden->getContent())->toBe($missing->getContent());
});

it('lets an account read itself', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)->getJson("/api/v1/users/{$member->public_id}")
        ->assertOk()
        ->assertJsonPath('user.id', $member->public_id);
});

it('lets an administrator read any account', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->member()->create();

    $this->actingAs($admin)->getJson("/api/v1/users/{$other->public_id}")
        ->assertOk()
        ->assertJsonPath('user.id', $other->public_id);
});

it('hides the user list from a non-administrator', function (string $role): void {
    $user = User::factory()->{$role}()->create();

    $this->actingAs($user)->getJson('/api/v1/users')->assertStatus(404);
})->with(['member', 'viewer']);

it('never exposes the integer key', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(3)->create();

    $body = $this->actingAs($admin)->getJson('/api/v1/users')->getContent();

    expect($body)->not->toContain('"id":1')
        ->and($body)->not->toContain('"id":2');

    foreach ($this->actingAs($admin)->getJson('/api/v1/users')->json('users') as $user) {
        expect($user['id'])->toHaveLength(26);
    }
});

it('refuses an account changing its own role', function (): void {
    $owner = User::factory()->owner()->freshlyAuthenticated()->create();

    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$owner->public_id}/role", ['role' => 'member'])
        ->assertStatus(403);

    expect($owner->refresh()->role)->toBe(Role::Owner);
});

it('refuses granting a role above the actor', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $target = User::factory()->member()->create();

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'owner'])
        ->assertStatus(403);

    expect($target->refresh()->role)->toBe(Role::Member);
});

it('refuses changing the role of an account above the actor', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $owner = User::factory()->owner()->create();

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$owner->public_id}/role", ['role' => 'member'])
        ->assertStatus(403);

    expect($owner->refresh()->role)->toBe(Role::Owner);
});

it('lets an owner promote another account to owner', function (): void {
    $owner = User::factory()->owner()->freshlyAuthenticated()->create();
    $target = User::factory()->member()->create();

    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'owner'])
        ->assertOk()
        ->assertJsonPath('user.role', 'owner');
});

it('keeps at least one owner when demoting', function (): void {
    $owner = User::factory()->owner()->freshlyAuthenticated()->create();
    $second = User::factory()->owner()->create();

    // Demoting one of two is fine.
    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$second->public_id}/role", ['role' => 'admin'])
        ->assertOk();

    // The remaining owner is the actor, and an account cannot demote itself, so
    // the instance keeps an owner either way.
    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$owner->public_id}/role", ['role' => 'admin'])
        ->assertStatus(403);

    expect(User::ownerCount())->toBe(1);
});

it('keeps at least one owner when deleting', function (): void {
    $owner = User::factory()->owner()->freshlyAuthenticated()->create();
    $other = User::factory()->owner()->create();

    $this->actingAs($owner)->deleteJson("/api/v1/users/{$other->public_id}")->assertNoContent();

    // Only one owner left; deleting it is refused.
    $this->actingAs($owner)->deleteJson("/api/v1/users/{$owner->public_id}")->assertStatus(422);

    expect(User::ownerCount())->toBe(1);
});

it('refuses a viewer any write action', function (): void {
    $viewer = User::factory()->viewer()->freshlyAuthenticated()->create();
    $target = User::factory()->member()->create();

    $this->actingAs($viewer)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'admin'])
        ->assertStatus(404);

    $this->actingAs($viewer)
        ->postJson('/api/v1/invitations', ['email' => 'x@example.test', 'role' => 'member'])
        ->assertStatus(404);
});
