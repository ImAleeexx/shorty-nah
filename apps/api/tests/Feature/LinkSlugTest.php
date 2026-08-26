<?php

declare(strict_types=1);

use App\Links\LinkException;
use App\Links\LinkService;
use App\Links\ReservedSlugs;
use App\Links\SlugAlphabet;
use App\Links\SlugAvailability;
use App\Links\SlugExhaustedException;
use App\Links\SlugGenerator;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Settings\SettingsStore;

function owner(): User
{
    return User::factory()->member()->create();
}

function verifiedDomain(string $host = 'go.example.com'): Domain
{
    return Domain::factory()->primary()->create(['host' => $host]);
}

function service(): LinkService
{
    return app(LinkService::class);
}

// --- 7.1 per-domain slug scoping ---

it('lets the same slug exist on two domains', function (): void {
    $first = verifiedDomain('a.example.com');
    $second = Domain::factory()->create(['host' => 'b.example.com']);
    $user = owner();

    $one = service()->create(['destination' => 'https://one.example.org', 'domain' => $first, 'slug' => 'launch'], $user);
    $two = service()->create(['destination' => 'https://two.example.org', 'domain' => $second, 'slug' => 'launch'], $user);

    expect($one->slug)->toBe('launch')
        ->and($two->slug)->toBe('launch')
        ->and($one->domain_id)->not->toBe($two->domain_id);
});

it('refuses a duplicate slug on the same domain', function (): void {
    $domain = verifiedDomain();
    $user = owner();

    service()->create(['destination' => 'https://one.example.org', 'domain' => $domain, 'slug' => 'launch'], $user);

    expect(fn () => service()->create(
        ['destination' => 'https://two.example.org', 'domain' => $domain, 'slug' => 'launch'],
        $user,
    ))->toThrow(LinkException::class, 'already in use on this domain');
});

it('refuses a slug already used by a soft-deleted link', function (): void {
    $domain = verifiedDomain();
    $user = owner();

    $link = service()->create(['destination' => 'https://one.example.org', 'domain' => $domain, 'slug' => 'retired'], $user);
    $link->delete();

    // Reissuing it would send traffic meant for the retired link somewhere new.
    expect(fn () => service()->create(
        ['destination' => 'https://two.example.org', 'domain' => $domain, 'slug' => 'retired'],
        $user,
    ))->toThrow(LinkException::class, 'already in use');
});

// --- 7.2 generation ---

it('generates a slug of the configured length from the alphabet', function (): void {
    app(SettingsStore::class)->set('slug.length', 9);
    $domain = verifiedDomain();

    $slug = app(SlugGenerator::class)->generateFor($domain);

    expect(mb_strlen($slug))->toBe(9)
        ->and(SlugAlphabet::isGeneratable($slug))->toBeTrue();
});

it('generates slugs that do not repeat', function (): void {
    $domain = verifiedDomain();
    $generator = app(SlugGenerator::class);

    $slugs = [];

    for ($i = 0; $i < 200; $i++) {
        $slugs[] = $generator->generateFor($domain);
    }

    expect(array_unique($slugs))->toHaveCount(200);
});

it('excludes ambiguous characters from every generated slug', function (): void {
    $domain = verifiedDomain();
    $generator = app(SlugGenerator::class);

    $combined = '';

    for ($i = 0; $i < 200; $i++) {
        $combined .= $generator->generateFor($domain);
    }

    // A slug gets read off a screen and typed into a phone; these four are the
    // pairs that get transcribed wrongly.
    foreach (['0', 'O', 'I', 'l'] as $ambiguous) {
        expect($combined)->not->toContain($ambiguous);
    }
});

it('retries past a collision', function (): void {
    $domain = verifiedDomain();
    app(SettingsStore::class)->set('slug.length', 4);

    // Occupy a slice of a deliberately small space, then require a fresh slug.
    $generator = app(SlugGenerator::class);

    for ($i = 0; $i < 30; $i++) {
        Link::factory()->forDomain($domain)->withSlug($generator->generateFor($domain))->create();
    }

    $slug = $generator->generateFor($domain);

    expect(Link::query()->where('domain_id', $domain->id)->where('slug', $slug)->exists())->toBeFalse();
});

// --- 7.3 custom slug validation ---

it('accepts a custom slug and preserves its case', function (): void {
    $domain = verifiedDomain();

    $link = service()->create(['destination' => 'https://example.org', 'domain' => $domain, 'slug' => 'SpringLaunch'], owner());

    expect($link->slug)->toBe('SpringLaunch');
});

it('refuses a reserved slug', function (string $slug): void {
    $domain = verifiedDomain();

    expect(fn () => service()->create(
        ['destination' => 'https://example.org', 'domain' => $domain, 'slug' => $slug],
        owner(),
    ))->toThrow(LinkException::class, 'reserved');
})->with(['api', 'setup', 'horizon', 'API', 'Setup', 'dashboard', 'robots.txt']);

it('refuses a custom slug outside the URL-safe set', function (string $slug): void {
    $domain = verifiedDomain();

    expect(fn () => service()->create(
        ['destination' => 'https://example.org', 'domain' => $domain, 'slug' => $slug],
        owner(),
    ))->toThrow(LinkException::class, 'may use only');
})->with(['has space', 'has/slash', 'has.dot', 'has%percent', 'has?query', 'has#hash']);

it('accepts an operator slug containing characters the generator avoids', function (string $slug): void {
    // The unambiguous alphabet exists for values nobody chose. Applying it here
    // would reject every word containing an l, which is most of them.
    $domain = verifiedDomain();

    $link = service()->create(
        ['destination' => 'https://example.org', 'domain' => $domain, 'slug' => $slug],
        owner(),
    );

    expect($link->slug)->toBe($slug);
})->with(['launch', 'blog', 'hello-world', 'spring_2026', 'Oslo0']);

it('refuses a slug that is too short or too long', function (string $slug): void {
    $domain = verifiedDomain();

    expect(fn () => service()->create(
        ['destination' => 'https://example.org', 'domain' => $domain, 'slug' => $slug],
        owner(),
    ))->toThrow(LinkException::class, 'between');
})->with(['ab', str_repeat('a', 65)]);

it('refuses every word on the reserved list', function (): void {
    $domain = verifiedDomain();
    $user = owner();

    // Asserting the list is self-consistent would be tautological; this asserts
    // the service actually refuses each one, whichever rule catches it.
    foreach (ReservedSlugs::all() as $reserved) {
        expect(fn () => service()->create(
            ['destination' => 'https://example.org', 'domain' => $domain, 'slug' => $reserved],
            $user,
        ))->toThrow(LinkException::class, 'reserved', "[{$reserved}] was not refused");
    }
});

it('raises a distinct error when the slug space is exhausted', function (): void {
    // The real space is 58^7, so exhaustion is unreachable against a database.
    // Availability is an injected collaborator precisely so this branch can be
    // exercised rather than left untested.
    app()->bind(SlugAvailability::class, fn (): SlugAvailability => new class implements SlugAvailability
    {
        public function isTaken(Domain $domain, string $slug): bool
        {
            return true;
        }
    });

    expect(fn () => app(SlugGenerator::class)->generateFor(verifiedDomain()))
        ->toThrow(SlugExhaustedException::class, 'Increase the configured slug length');
});

it('never returns a duplicate instead of failing', function (): void {
    app()->bind(SlugAvailability::class, fn (): SlugAvailability => new class implements SlugAvailability
    {
        public function isTaken(Domain $domain, string $slug): bool
        {
            return true;
        }
    });

    // Returning a taken slug would silently overwrite someone else's link, so the
    // only acceptable outcome is an exception.
    $domain = verifiedDomain();

    try {
        app(SlugGenerator::class)->generateFor($domain);
        $this->fail('Generation returned a slug when every candidate was taken.');
    } catch (SlugExhaustedException) {
        expect(true)->toBeTrue();
    }
});
