<?php

declare(strict_types=1);

use App\ClickHouse\Connection;
use App\Clicks\ClickWriter;
use App\Enums\Role;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Providers\ClickHouseServiceProvider;
use Illuminate\Support\Str;

function overviewEvents(): Connection
{
    return app(ClickHouseServiceProvider::WRITER);
}

function overviewLink(Domain $domain, string $slug, ?User $creator = null): Link
{
    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => $slug,
        'destination' => 'https://example.com/'.$slug,
        'created_by' => $creator?->id,
        'click_count' => 0,
    ])->save();

    return $link;
}

function seedOverviewClicks(int $linkId, int $count, string $country = 'ES'): void
{
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'click_id' => (string) Str::ulid(),
            'link_id' => $linkId,
            'domain_id' => 1,
            'occurred_at' => now()->subHours($i % 48)->format('Y-m-d H:i:s'),
            'visitor_hash' => hash('sha256', 'visitor-'.$linkId.'-'.$i),
            'is_automated' => 0,
            'is_duplicate' => 0,
            'country_code' => $country,
            'redirect_mode' => 'direct',
        ];
    }

    overviewEvents()->insert(ClickWriter::TABLE, $rows);
}

beforeEach(function (): void {
    if (! overviewEvents()->ping()) {
        $this->markTestSkipped('ClickHouse is not reachable. Start the dev stack with `make up`.');
    }

    cache()->flush();
    overviewEvents()->statement('TRUNCATE TABLE IF EXISTS '.ClickWriter::TABLE);
    overviewEvents()->statement('TRUNCATE TABLE IF EXISTS click_hourly');
    overviewEvents()->statement('TRUNCATE TABLE IF EXISTS click_by_country');

    // Waited for, not assumed. A truncation is not immediately visible to the
    // next read, and a baseline taken too early makes every delta below
    // overshoot by whatever was still on its way out — which is how this
    // reported 63 where 40 was seeded.
    $emptied = false;

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $rows = overviewEvents()->select('SELECT count() AS total FROM click_hourly');

        if ((int) ($rows[0]['total'] ?? 0) === 0) {
            $emptied = true;

            break;
        }

        usleep(100_000);
    }

    expect($emptied)->toBeTrue('the event store did not settle after truncation');
});

/**
 * Asserted as a delta rather than an absolute count.
 *
 * The event store is shared with the running development instance and is not
 * rolled back between tests the way Postgres is, so a link id created here can
 * collide with one that already has rollup rows against it. Truncating first
 * looked sufficient and was not — this failed once at 28 where 5 was expected,
 * on data no test in the run had written. Measuring the change this test causes
 * is immune to whatever was already there.
 */
function overviewCounted(User $actor): int
{
    return (int) test()->actingAs($actor)->getJson('/api/v1/overview')->json('overview.totals.counted');
}

it('reports the figures it actually measured', function (): void {
    $domain = Domain::factory()->create(['host' => 'ov.example.test', 'verified_at' => now()]);
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();

    $link = overviewLink($domain, 'overvw01', $admin);

    $before = overviewCounted($admin);

    seedOverviewClicks($link->id, 40);

    // The screen this replaces rendered a literal zero regardless of the data,
    // so the assertion that matters is that the figure moves with the data.
    $this->actingAs($admin)
        ->getJson('/api/v1/overview')
        ->assertOk()
        ->assertJsonPath('overview.links_total', 1);

    expect(overviewCounted($admin) - $before)->toBe(40);
});

it('scopes every figure to the links the account may read', function (): void {
    $domain = Domain::factory()->create(['host' => 'ov2.example.test', 'verified_at' => now()]);
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $member = User::factory()->create(['role' => Role::Member]);

    $theirs = overviewLink($domain, 'ovmine01', $member);
    $others = overviewLink($domain, 'ovother1', $admin);

    $memberBefore = overviewCounted($member);
    $adminBefore = overviewCounted($admin);

    seedOverviewClicks($theirs->id, 5, 'PT');
    seedOverviewClicks($others->id, 50, 'FR');

    // An account that may read only its own links must not learn the instance
    // total by reading the dashboard: the member sees five of the fifty-five.
    $this->actingAs($member)
        ->getJson('/api/v1/overview')
        ->assertOk()
        ->assertJsonPath('overview.links_total', 1);

    expect(overviewCounted($member) - $memberBefore)->toBe(5)
        ->and(overviewCounted($admin) - $adminBefore)->toBe(55);
});

it('returns a full run of days including the quiet ones', function (): void {
    $domain = Domain::factory()->create(['host' => 'ov3.example.test', 'verified_at' => now()]);
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();

    $link = overviewLink($domain, 'overvw03', $admin);
    seedOverviewClicks($link->id, 3);

    $daily = $this->actingAs($admin)->getJson('/api/v1/overview')->assertOk()->json('overview.daily');

    // Gaps filled server-side: a sparkline that closes over a quiet day draws a
    // shape that never happened.
    expect($daily)->toHaveCount(31)
        ->and(array_column($daily, 'counted'))->toContain(0);
});

it('answers zeroes rather than failing for an account with no links', function (): void {
    $member = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($member)
        ->getJson('/api/v1/overview')
        ->assertOk()
        ->assertJsonPath('overview.links_total', 0)
        ->assertJsonPath('overview.totals.counted', 0)
        ->assertJsonPath('overview.countries', []);
});

it('is refused entirely when signed out', function (): void {
    $this->getJson('/api/v1/overview')->assertStatus(401);
});
