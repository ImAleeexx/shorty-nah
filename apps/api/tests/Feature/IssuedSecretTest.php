<?php

declare(strict_types=1);

use App\Auth\InvitationService;
use App\Enums\Role;
use App\Http\Controllers\ApiTokenController;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('stores an invitation token only as a hash', function (): void {
    $admin = User::factory()->admin()->create();

    $issued = app(InvitationService::class)->issue($admin, 'new@example.test', Role::Member);

    $stored = DB::table('invitations')->where('id', $issued['invitation']->id)->first();

    expect($stored)->not->toBeNull()
        ->and($stored->token_hash)->not->toBe($issued['token'])
        ->and($stored->token_hash)->toBe(hash('sha256', $issued['token']))
        ->and(json_encode((array) $stored))->not->toContain($issued['token']);
});

it('never serialises the invitation token hash', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();

    $this->actingAs($admin)->postJson('/api/v1/invitations', [
        'email' => 'new@example.test',
        'role' => 'member',
    ])->assertCreated();

    $body = $this->actingAs($admin)->getJson('/api/v1/invitations')->getContent();

    expect($body)->not->toContain('token_hash');
});

it('shows the invitation token once, at creation', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();

    $created = $this->actingAs($admin)->postJson('/api/v1/invitations', [
        'email' => 'new@example.test',
        'role' => 'member',
    ])->assertCreated();

    $token = $created->json('token');

    expect($token)->toBeString()->toHaveLength(48);

    // Never again, in any later read.
    expect($this->actingAs($admin)->getJson('/api/v1/invitations')->getContent())
        ->not->toContain($token);
});

it('refuses an invitation granting a role above the inviter', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();

    $this->actingAs($admin)->postJson('/api/v1/invitations', [
        'email' => 'new@example.test',
        'role' => 'owner',
    ])->assertStatus(403);

    expect(Invitation::query()->count())->toBe(0);
});

it('revokes an invitation', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $issued = app(InvitationService::class)->issue($admin, 'new@example.test', Role::Member);

    $this->actingAs($admin)
        ->deleteJson("/api/v1/invitations/{$issued['invitation']->public_id}")
        ->assertNoContent();

    expect(app(InvitationService::class)->find($issued['token']))->toBeNull();
});

it('shows an API token once and stores only its hash', function (): void {
    $member = User::factory()->member()->freshlyAuthenticated()->create();

    $created = $this->actingAs($member)->postJson('/api/v1/tokens', [
        'name' => 'CI publisher',
        'abilities' => ['links:write'],
    ])->assertCreated();

    $plain = $created->json('token');

    // Sanctum's plaintext form is "{id}|{prefix}{random}". The prefix exists so
    // a leaked token is detectable by a secret scanner.
    expect($plain)->toBeString()->toContain('|shortynah_');

    $stored = DB::table('personal_access_tokens')->where('name', 'CI publisher')->first();

    expect($stored->token)->not->toContain($plain)
        ->and($this->actingAs($member)->getJson('/api/v1/tokens')->getContent())->not->toContain($plain);
});

it('refuses an ability outside the published set', function (): void {
    $member = User::factory()->member()->freshlyAuthenticated()->create();

    $this->actingAs($member)->postJson('/api/v1/tokens', [
        'name' => 'Overreach',
        'abilities' => ['users:delete'],
    ])->assertStatus(422)->assertJsonValidationErrors('abilities.0');

    expect(ApiTokenController::ABILITIES)->not->toContain('users:delete');
});

it('authorises a request carrying a token within its abilities', function (): void {
    $member = User::factory()->member()->create();
    $token = $member->createToken('Reader', ['links:read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/users/{$member->public_id}")
        ->assertOk();
});

it('rejects a revoked token', function (): void {
    $member = User::factory()->member()->create();
    $token = $member->createToken('Reader', ['links:read'])->plainTextToken;

    $member->tokens()->delete();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/users/{$member->public_id}")
        ->assertStatus(401);
});

it('refuses to revoke a token belonging to someone else', function (): void {
    $owner = User::factory()->member()->create();
    $intruder = User::factory()->member()->freshlyAuthenticated()->create();

    $token = $owner->createToken('Theirs', ['links:read']);

    $this->actingAs($intruder)
        ->deleteJson('/api/v1/tokens/'.$token->accessToken->getKey())
        ->assertStatus(404);

    expect($owner->tokens()->count())->toBe(1);
});
