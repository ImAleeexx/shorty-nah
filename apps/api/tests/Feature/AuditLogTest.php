<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Enums\Role;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

function auditEntries(?AuditAction $action = null): Collection
{
    $query = DB::table('audit_entries')->orderByDesc('id');

    if ($action instanceof AuditAction) {
        $query->where('action', $action->value);
    }

    return $query->get();
}

/**
 * Membership, tokens and domains sit behind recent authentication, so an
 * administrator used for those has to have signed in lately.
 */
function auditAdmin(): User
{
    return User::factory()->freshlyAuthenticated()->create(['role' => Role::Admin]);
}

beforeEach(function (): void {
    RateLimiter::clear('auth:account:'.sha1('operator@example.test'));
    RateLimiter::clear('auth:source:'.sha1('127.0.0.1'));
});

it('records a successful sign-in with actor, action, source and time', function (): void {
    $user = User::factory()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $entry = auditEntries(AuditAction::SignInSucceeded)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->actor_id)->toBe($user->id)
        ->and($entry->actor_email)->toBe('operator@example.test')
        ->and($entry->source_hash)->toBeString()->not->toBeEmpty()
        ->and($entry->created_at)->not->toBeNull();
});

it('records a failed sign-in without the submitted credential', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => 'the-wrong-password-entirely',
    ])->assertStatus(422);

    $entry = auditEntries(AuditAction::SignInFailed)->first();

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry))->not->toContain('the-wrong-password-entirely');
});

it('never records a raw address, only a derived identifier', function (): void {
    User::factory()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    $entry = auditEntries(AuditAction::SignInSucceeded)->first();

    expect($entry->source_hash)->not->toContain('127.0.0.1')
        ->and(mb_strlen((string) $entry->source_hash))->toBe(64);
});

it('records a role change with what it changed from and to', function (): void {
    $admin = auditAdmin();
    $target = User::factory()->create(['role' => Role::Viewer]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'member'])
        ->assertOk();

    $entry = auditEntries(AuditAction::RoleChanged)->first();
    $context = json_decode((string) $entry->context, true);

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->target_id)->toBe($target->public_id)
        ->and($context)->toBe(['from' => 'viewer', 'to' => 'member']);
});

it('records an invitation without recording its token', function (): void {
    $admin = auditAdmin();

    $response = $this->actingAs($admin)
        ->postJson('/api/v1/invitations', ['email' => 'invited@example.test', 'role' => 'member'])
        ->assertStatus(201);

    $token = $response->json('token');
    $entry = auditEntries(AuditAction::InvitationIssued)->first();

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry))->not->toContain($token);
});

it('records a token creation without the token itself', function (): void {
    $user = User::factory()->freshlyAuthenticated()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/tokens', ['name' => 'ci', 'abilities' => ['links:read']])
        ->assertStatus(201);

    $entry = auditEntries(AuditAction::TokenCreated)->first();

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry))->not->toContain($response->json('token'));
});

it('records a settings change by key and never by value', function (): void {
    $admin = auditAdmin();

    $this->actingAs($admin)
        ->putJson('/api/v1/settings', ['settings' => ['geo.maxmind_license_key' => 'the-licence-value']])
        ->assertOk();

    $entry = auditEntries(AuditAction::SettingsChanged)->first();

    expect($entry)->not->toBeNull()
        ->and((string) $entry->context)->toContain('geo.maxmind_license_key')
        ->and(json_encode($entry))->not->toContain('the-licence-value');
});

it('records a link password change without the password', function (): void {
    $user = User::factory()->create();
    $domain = Domain::factory()->create(['verified_at' => now()]);
    $link = Link::factory()->create(['created_by' => $user->id, 'domain_id' => $domain->id]);

    $this->actingAs($user)
        ->patchJson("/api/v1/links/{$link->public_id}", ['password' => 'the-link-secret'])
        ->assertOk();

    $entry = auditEntries(AuditAction::LinkPasswordChanged)->first();

    expect($entry)->not->toBeNull()
        ->and(json_encode($entry))->not->toContain('the-link-secret');
});

it('records a domain addition and removal', function (): void {
    $admin = auditAdmin();

    // The first domain on an instance becomes primary and cannot be deleted, so
    // the one under test is the second.
    Domain::factory()->create(['is_primary' => true, 'verified_at' => now()]);

    $this->actingAs($admin)
        ->postJson('/api/v1/domains', ['host' => 'go.example.test'])
        ->assertStatus(201);

    expect(auditEntries(AuditAction::DomainAdded))->toHaveCount(1);

    $domain = Domain::query()->where('host', 'go.example.test')->firstOrFail();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/domains/{$domain->public_id}")
        ->assertStatus(204);

    expect(auditEntries(AuditAction::DomainRemoved))->toHaveCount(1);
});

it('records an export', function (): void {
    $user = User::factory()->create();
    $domain = Domain::factory()->create(['verified_at' => now()]);
    $link = Link::factory()->create(['created_by' => $user->id, 'domain_id' => $domain->id]);

    $this->actingAs($user)->get("/api/v1/links/{$link->public_id}/export")->assertOk();

    expect(auditEntries(AuditAction::AnalyticsExported))->toHaveCount(1);
});

it('keeps an entry after the account it names is deleted', function (): void {
    $admin = User::factory()->freshlyAuthenticated()->create(['role' => Role::Owner]);
    $target = User::factory()->create(['role' => Role::Member, 'email' => 'gone@example.test']);

    $this->actingAs($admin)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'viewer'])
        ->assertOk();

    $this->actingAs($admin)
        ->deleteJson("/api/v1/users/{$target->public_id}")
        ->assertStatus(204);

    $entry = auditEntries(AuditAction::RoleChanged)->first();

    // The actor link is nulled, but what happened is still legible.
    expect($entry)->not->toBeNull()
        ->and($entry->target_id)->toBe($target->public_id);
});

it('records one entry per event rather than several', function (): void {
    $user = User::factory()->create(['email' => 'operator@example.test']);

    $this->postJson('/api/v1/auth/session', [
        'email' => 'operator@example.test',
        'password' => UserFactory::PASSWORD,
    ])->assertOk();

    expect(auditEntries(AuditAction::SignInSucceeded))->toHaveCount(1);
});

// --- 14.15 the viewer ---

it('lists entries newest first for an owner', function (): void {
    $owner = User::factory()->freshlyAuthenticated()->create(['role' => Role::Owner]);
    $target = User::factory()->create(['role' => Role::Viewer]);

    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'member'])
        ->assertOk();

    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'viewer'])
        ->assertOk();

    $response = $this->actingAs($owner)->getJson('/api/v1/audit')->assertOk();

    $entries = $response->json('entries');
    $context = $entries[0]['context'];

    // The most recent change is the one that put them back to viewer.
    expect($context['to'])->toBe('viewer')
        ->and($entries[1]['context']['to'])->toBe('member');
});

it('filters the log by actor, action and period', function (): void {
    $owner = User::factory()->freshlyAuthenticated()->create(['role' => Role::Owner]);
    $target = User::factory()->create(['role' => Role::Viewer]);

    $this->actingAs($owner)
        ->patchJson("/api/v1/users/{$target->public_id}/role", ['role' => 'member'])
        ->assertOk();

    $this->actingAs($owner)
        ->getJson('/api/v1/audit?action='.AuditAction::RoleChanged->value)
        ->assertOk()
        ->assertJsonCount(1, 'entries');

    $this->actingAs($owner)
        ->getJson('/api/v1/audit?action='.AuditAction::DomainAdded->value)
        ->assertOk()
        ->assertJsonCount(0, 'entries');

    $this->actingAs($owner)
        ->getJson('/api/v1/audit?actor='.urlencode($owner->email))
        ->assertOk()
        ->assertJsonCount(1, 'entries');

    $this->actingAs($owner)
        ->getJson('/api/v1/audit?from='.now()->addDay()->toDateString())
        ->assertOk()
        ->assertJsonCount(0, 'entries');
});

it('never returns a raw address to the viewer', function (): void {
    $owner = User::factory()->freshlyAuthenticated()->create(['role' => Role::Owner]);

    $this->actingAs($owner)->getJson('/api/v1/audit')->assertOk();

    $response = $this->actingAs($owner)->getJson('/api/v1/audit')->assertOk();

    expect(json_encode($response->json()))->not->toContain('127.0.0.1');
});

it('hides the audit log from an administrator who is not an owner', function (): void {
    $admin = User::factory()->freshlyAuthenticated()->create(['role' => Role::Admin]);

    $this->actingAs($admin)->getJson('/api/v1/audit')->assertStatus(404);
});

it('offers no way to alter an entry through the API', function (): void {
    $owner = User::factory()->freshlyAuthenticated()->create(['role' => Role::Owner]);

    // There is no write route at all, which is the point: the surface cannot be
    // misused because it does not exist.
    $this->actingAs($owner)->postJson('/api/v1/audit', [])->assertStatus(405);
    $this->actingAs($owner)->deleteJson('/api/v1/audit/1')->assertStatus(404);
});
