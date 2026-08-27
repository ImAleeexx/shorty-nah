<?php

declare(strict_types=1);

use App\Clicks\GeoResolver;
use App\Clicks\GeoResult;
use App\Enums\Role;
use App\Enums\RuleKind;
use App\Links\LinkRuleService;
use App\Links\RoutingContext;
use App\Links\RoutingRule;
use App\Links\RuleEvaluator;
use App\Models\Domain;
use App\Models\Link;
use App\Models\LinkRule;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

function routedLink(string $slug = 'ruled001'): Link
{
    $domain = Domain::factory()->create(['host' => 'rules.example.test', 'verified_at' => now()]);

    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => $slug,
        'destination' => 'https://example.com/default',
        'redirect_mode' => 'direct',
        'click_count' => 0,
    ])->save();

    return $link;
}

function answerGeo(string $country): void
{
    app()->instance(GeoResolver::class, new class($country) implements GeoResolver
    {
        public function __construct(private readonly string $country) {}

        public function missingDatabases(): bool
        {
            return $this->country === '';
        }

        public function lookup(?string $address): GeoResult
        {
            return $this->country === '' ? GeoResult::unknown() : new GeoResult($this->country, '', '', 3352, 'Telefonica');
        }
    });
}

function reachRuled(Link $link, array $headers = [], string $address = '198.51.100.10')
{
    RateLimiter::clear('redirect:'.$address);

    return test()->call(
        'GET',
        'http://rules.example.test/'.$link->slug,
        server: array_merge(['REMOTE_ADDR' => $address], $headers),
    );
}

beforeEach(function (): void {
    cache()->flush();
    answerGeo('ES');
});

// --- 3.5 country ---

it('routes a matching country to that rule', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/spain')->create();

    reachRuled($link)->assertRedirect('https://example.com/spain');
});

it('falls through to the link destination when no rule matches', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'FR', 'https://example.com/france')->create();

    reachRuled($link)->assertRedirect('https://example.com/default');
});

it('matches any country named in one rule', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'FR, ES, PT', 'https://example.com/iberia')->create();

    reachRuled($link)->assertRedirect('https://example.com/iberia');
});

// --- 3.10 country with no geographic data ---

it('does not match a country rule when no geographic databases are present', function (): void {
    answerGeo('');

    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/spain')->create();

    // A condition nobody can evaluate must not silently capture traffic.
    reachRuled($link)->assertRedirect('https://example.com/default');
});

// --- 3.6 device ---

it('routes a mobile visitor to a device rule', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Device, 'mobile', 'https://example.com/app')->create();

    reachRuled($link, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'])
        ->assertRedirect('https://example.com/app');
});

it('leaves a desktop visitor on the default for a mobile rule', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Device, 'mobile', 'https://example.com/app')->create();

    reachRuled($link, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'])
        ->assertRedirect('https://example.com/default');
});

// --- 3.7 language ---

it('routes on the preferred language rather than the first one written', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Language, 'es', 'https://example.com/es')->create();

    // English is written first but Spanish carries the higher quality.
    reachRuled($link, ['HTTP_ACCEPT_LANGUAGE' => 'en;q=0.5,es'])
        ->assertRedirect('https://example.com/es');
});

it('matches a language rule on its primary subtag', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Language, 'es', 'https://example.com/es')->create();

    reachRuled($link, ['HTTP_ACCEPT_LANGUAGE' => 'es-419,es;q=0.9'])
        ->assertRedirect('https://example.com/es');
});

it('does not match a language rule when the header is absent', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Language, 'es', 'https://example.com/es')->create();

    reachRuled($link)->assertRedirect('https://example.com/default');
});

// --- 3.8 time window ---

it('routes inside a time window and not outside it', function (): void {
    app(SettingsStore::class)->set('analytics.timezone', 'UTC');

    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::TimeWindow, '09:00-17:00', 'https://example.com/office')->create();

    $this->travelTo(now()->setTimezone('UTC')->setTime(11, 0));
    reachRuled($link)->assertRedirect('https://example.com/office');

    cache()->flush();
    $this->travelTo(now()->setTimezone('UTC')->setTime(21, 0));
    reachRuled($link)->assertRedirect('https://example.com/default');
});

it('treats a window ending before it starts as crossing midnight', function (): void {
    app(SettingsStore::class)->set('analytics.timezone', 'UTC');

    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::TimeWindow, '22:00-06:00', 'https://example.com/overnight')->create();

    $this->travelTo(now()->setTimezone('UTC')->setTime(23, 30));
    reachRuled($link)->assertRedirect('https://example.com/overnight');

    cache()->flush();
    $this->travelTo(now()->setTimezone('UTC')->setTime(3, 0));
    reachRuled($link)->assertRedirect('https://example.com/overnight');

    cache()->flush();
    $this->travelTo(now()->setTimezone('UTC')->setTime(12, 0));
    reachRuled($link)->assertRedirect('https://example.com/default');
});

// --- 3.9 ordering ---

it('takes the earlier rule when two match', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/first')->create();
    LinkRule::factory()->forLink($link)->at(1)->of(RuleKind::Country, 'ES', 'https://example.com/second')->create();

    reachRuled($link)->assertRedirect('https://example.com/first');
});

// --- 3.3 / 3.4 cache ---

it('resolves a rule-carrying link from cache without a query', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/spain')->create();

    reachRuled($link)->assertRedirect('https://example.com/spain');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    reachRuled($link, [], '198.51.100.11')->assertRedirect('https://example.com/spain');

    expect($queries)->toBe(0);
});

it('takes effect on the next request when a rule is added', function (): void {
    $link = routedLink();

    reachRuled($link)->assertRedirect('https://example.com/default');

    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/spain')->create();

    reachRuled($link, [], '198.51.100.12')->assertRedirect('https://example.com/spain');
});

it('takes effect on the next request when a rule is removed', function (): void {
    $link = routedLink();
    $rule = LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/spain')->create();

    reachRuled($link)->assertRedirect('https://example.com/spain');

    $rule->delete();

    reachRuled($link, [], '198.51.100.13')->assertRedirect('https://example.com/default');
});

it('takes effect on the next request when rules are reordered', function (): void {
    $link = routedLink();
    $first = LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Country, 'ES', 'https://example.com/first')->create();
    $second = LinkRule::factory()->forLink($link)->at(1)->of(RuleKind::Country, 'ES', 'https://example.com/second')->create();

    reachRuled($link)->assertRedirect('https://example.com/first');

    // Through a temporary position, because the pair is unique per link.
    $first->forceFill(['position' => 9])->save();
    $second->forceFill(['position' => 0])->save();
    $first->forceFill(['position' => 1])->save();

    reachRuled($link, [], '198.51.100.14')->assertRedirect('https://example.com/second');
});

// --- 3.12 no rules, no change ---

it('serves a link with no rules exactly as before', function (): void {
    $link = routedLink();

    $response = reachRuled($link);

    $response->assertRedirect('https://example.com/default');
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    // Asserted on the directives rather than the header string: Symfony
    // normalises and reorders Cache-Control, so a literal comparison tests the
    // framework's formatting rather than the policy.
    $cacheControl = $response->headers->get('Cache-Control') ?? '';

    foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directive) {
        expect($cacheControl)->toContain($directive);
    }
});

// --- the evaluator in isolation ---

it('refuses a malformed time window rather than matching everything', function (): void {
    $evaluator = new RuleEvaluator;

    $context = new RoutingContext('ES', 'desktop', ['en'], 600);

    foreach (['not-a-window', '25:00-26:00', '09:00', '09:00-09:00'] as $malformed) {
        $rule = new RoutingRule(RuleKind::TimeWindow, $malformed, 'https://example.com/bad');

        expect($evaluator->destinationFor([$rule], $context, 'https://example.com/fallback'))
            ->toBe('https://example.com/fallback');
    }
});

it('routes a tablet and a desktop by the same three-word vocabulary', function (): void {
    $link = routedLink();
    LinkRule::factory()->forLink($link)->at(0)->of(RuleKind::Device, 'tablet', 'https://example.com/tablet')->create();

    reachRuled($link, ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'])
        ->assertRedirect('https://example.com/tablet');
});

// --- 3.1 / 3.2 / 3.11 writing rules through the API ---

it('replaces a link\'s rules as an ordered set', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $link = routedLink('apirule1');

    $this->actingAs($admin)
        ->putJson('/api/v1/links/'.$link->public_id.'/rules', [
            'rules' => [
                ['kind' => 'country', 'value' => 'ES', 'destination' => 'https://example.com/es'],
                ['kind' => 'device', 'value' => 'mobile', 'destination' => 'https://example.com/app'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('rules.0.value', 'ES')
        ->assertJsonPath('rules.1.kind', 'device');

    // Written again with the order swapped: the set is replaced, not appended to.
    $this->actingAs($admin)
        ->putJson('/api/v1/links/'.$link->public_id.'/rules', [
            'rules' => [
                ['kind' => 'device', 'value' => 'mobile', 'destination' => 'https://example.com/app'],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'rules');

    expect(LinkRule::query()->where('link_id', $link->id)->count())->toBe(1);
});

it('refuses a rule destination the instance would refuse for a link', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $link = routedLink('apirule2');

    // A rule must not become the way around a refusal that applies everywhere
    // else.
    $this->actingAs($admin)
        ->putJson('/api/v1/links/'.$link->public_id.'/rules', [
            'rules' => [['kind' => 'country', 'value' => 'ES', 'destination' => 'http://127.0.0.1:9/private']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rules');

    expect(LinkRule::query()->where('link_id', $link->id)->count())->toBe(0);
});

it('refuses more rules than a link may carry', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $link = routedLink('apirule3');

    $rules = [];

    for ($i = 0; $i <= LinkRuleService::MAX_PER_LINK; $i++) {
        $rules[] = ['kind' => 'country', 'value' => 'ES', 'destination' => 'https://example.com/'.$i];
    }

    $this->actingAs($admin)
        ->putJson('/api/v1/links/'.$link->public_id.'/rules', ['rules' => $rules])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rules');
});

it('refuses a malformed value for each kind', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $link = routedLink('apirule4');

    $cases = [
        ['kind' => 'country', 'value' => 'Spain'],
        ['kind' => 'device', 'value' => 'smartphone'],
        ['kind' => 'language', 'value' => 'not a tag'],
        ['kind' => 'time_window', 'value' => '9am-5pm'],
        ['kind' => 'referrer', 'value' => 'example.com'],
    ];

    foreach ($cases as $case) {
        $this->actingAs($admin)
            ->putJson('/api/v1/links/'.$link->public_id.'/rules', [
                'rules' => [$case + ['destination' => 'https://example.com/x']],
            ])
            ->assertStatus(422);
    }
});

it('answers as though the link does not exist for an account that cannot see it', function (): void {
    $link = routedLink('apirule5');
    $stranger = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($stranger)
        ->getJson('/api/v1/links/'.$link->public_id.'/rules')
        ->assertStatus(404);

    $this->actingAs($stranger)
        ->putJson('/api/v1/links/'.$link->public_id.'/rules', ['rules' => []])
        ->assertStatus(404);
});

it('keeps positions unique and contiguous after a replace', function (): void {
    $admin = User::factory()->admin()->freshlyAuthenticated()->create();
    $link = routedLink('apirule6');

    $this->actingAs($admin)
        ->putJson('/api/v1/links/'.$link->public_id.'/rules', [
            'rules' => [
                ['kind' => 'country', 'value' => 'ES', 'destination' => 'https://example.com/a'],
                ['kind' => 'country', 'value' => 'FR', 'destination' => 'https://example.com/b'],
                ['kind' => 'country', 'value' => 'PT', 'destination' => 'https://example.com/c'],
            ],
        ])->assertOk();

    $positions = LinkRule::query()->where('link_id', $link->id)->orderBy('position')->pluck('position')->all();

    expect($positions)->toBe([0, 1, 2]);
});
