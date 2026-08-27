<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Jobs\ProcessLinkImport;
use App\Links\LinkCsv;
use App\Models\Domain;
use App\Models\Link;
use App\Models\LinkImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function transferDomain(): Domain
{
    return Domain::factory()->create([
        'host' => 'bulk.example.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
}

function transferOperator(): User
{
    return User::factory()->admin()->freshlyAuthenticated()->create();
}

function csvFile(string $body): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'links').'.csv';
    file_put_contents($path, $body);

    return new UploadedFile($path, 'links.csv', 'text/csv', null, true);
}

function runImport(User $actor, Domain $domain, string $body, bool $dryRun = false): LinkImport
{
    $response = test()->actingAs($actor)->post('/api/v1/links/import', [
        'file' => csvFile($body),
        'domain' => $domain->public_id,
        'dry_run' => $dryRun,
    ]);

    $response->assertStatus(202);

    // The queue connection is sync under test, so dispatching has already run
    // the batch by the time the request returns. Calling handle() here as well
    // would process every row a second time, and the second pass fails on the
    // slugs the first pass just took.
    return LinkImport::query()->where('public_id', $response->json('import.id'))->firstOrFail();
}

beforeEach(function (): void {
    cache()->flush();
});

// --- 6.1 the format ---

it('refuses a file with no recognisable header', function (): void {
    $domain = transferDomain();

    $this->actingAs(transferOperator())
        ->post('/api/v1/links/import', [
            'file' => csvFile("name,url\nx,https://example.com\n"),
            'domain' => $domain->public_id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(LinkImport::query()->count())->toBe(0);
});

it('refuses a file with a header and no rows', function (): void {
    $domain = transferDomain();

    $this->actingAs(transferOperator())
        ->post('/api/v1/links/import', [
            'file' => csvFile("destination\n"),
            'domain' => $domain->public_id,
        ])
        ->assertStatus(422);
});

it('reads a file written by a spreadsheet with a byte-order mark', function (): void {
    $parsed = LinkCsv::parse("\xEF\xBB\xBFdestination,slug\nhttps://example.com/a,bommark1\n");

    // Without stripping it the first column is named "\xEF\xBB\xBFdestination"
    // and the required-column check fails on a file that is perfectly valid.
    expect($parsed['rows'][0]['destination'])->toBe('https://example.com/a');
});

// --- 6.4 / 6.5 / 6.6 / 6.7 importing ---

it('creates every link in a valid file', function (): void {
    $domain = transferDomain();

    $import = runImport(transferOperator(), $domain, implode("\n", [
        'destination,slug',
        'https://example.com/one,bulkone1',
        'https://example.com/two,bulktwo1',
        '',
    ]));

    expect($import->status)->toBe(LinkImport::STATUS_FINISHED)
        ->and($import->created_rows)->toBe(2)
        ->and($import->failed_rows)->toBe(0)
        ->and(Link::query()->where('slug', 'bulkone1')->exists())->toBeTrue()
        ->and(Link::query()->where('slug', 'bulktwo1')->exists())->toBeTrue();
});

it('fails one bad row alone and reports the reason against it', function (): void {
    $domain = transferDomain();

    $import = runImport(transferOperator(), $domain, implode("\n", [
        'destination,slug',
        'https://example.com/good,bulkgd01',
        'not-a-url,bulkbad1',
        'https://example.com/also,bulkgd02',
        '',
    ]));

    expect($import->created_rows)->toBe(2)
        ->and($import->failed_rows)->toBe(1)
        ->and($import->rows[1]['outcome'])->toBe('refused')
        ->and($import->rows[1]['reason'])->toBeString()->not->toBeEmpty()
        ->and(Link::query()->where('slug', 'bulkgd01')->exists())->toBeTrue()
        ->and(Link::query()->where('slug', 'bulkgd02')->exists())->toBeTrue()
        ->and(Link::query()->where('slug', 'bulkbad1')->exists())->toBeFalse();
});

it('refuses an imported destination that resolves to a private address', function (): void {
    $domain = transferDomain();

    $import = runImport(transferOperator(), $domain, implode("\n", [
        'destination,slug',
        'http://127.0.0.1:9/private,bulkloop',
        '',
    ]));

    // The same refusal single creation gives, because an import goes through the
    // same service.
    expect($import->failed_rows)->toBe(1)
        ->and($import->rows[0]['outcome'])->toBe('refused')
        ->and(Link::query()->where('slug', 'bulkloop')->exists())->toBeFalse();
});

it('refuses a slug already in use and leaves the existing link alone', function (): void {
    $domain = transferDomain();

    $existing = new Link;
    $existing->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => 'bulktakn',
        'destination' => 'https://example.com/original',
        'click_count' => 0,
    ])->save();

    $import = runImport(transferOperator(), $domain, implode("\n", [
        'destination,slug',
        'https://example.com/replacement,bulktakn',
        '',
    ]));

    expect($import->failed_rows)->toBe(1)
        ->and($existing->refresh()->destination)->toBe('https://example.com/original');
});

// --- 6.8 dry run ---

it('reports every row and creates nothing on a dry run', function (): void {
    $domain = transferDomain();

    $import = runImport(transferOperator(), $domain, implode("\n", [
        'destination,slug',
        'https://example.com/one,dryrun01',
        'not-a-url,dryrun02',
        '',
    ]), dryRun: true);

    expect($import->created_rows)->toBe(1)
        ->and($import->failed_rows)->toBe(1)
        ->and($import->rows[0]['outcome'])->toBe('would be created')
        ->and(Link::query()->where('slug', 'dryrun01')->exists())->toBeFalse();
});

// --- 6.2 / 6.3 export ---

it('exports the links an account may read, and no others', function (): void {
    $domain = transferDomain();
    $owner = transferOperator();
    $member = User::factory()->create(['role' => Role::Member]);

    foreach ([['mineone1', $owner], ['theirs01', $member]] as [$slug, $creator]) {
        $link = new Link;
        $link->forceFill([
            'public_id' => (string) Str::ulid(),
            'domain_id' => $domain->id,
            'slug' => $slug,
            'destination' => 'https://example.com/'.$slug,
            'created_by' => $creator->id,
            'click_count' => 0,
        ])->save();
    }

    $body = $this->actingAs($member)->get('/api/v1/links/export')->assertOk()->getContent();

    expect($body)->toContain('theirs01')->not->toContain('mineone1');
});

it('records that a link is protected without carrying the password', function (): void {
    $domain = transferDomain();

    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => 'guarded1',
        'destination' => 'https://example.com/guarded',
        'password_hash' => Hash::make('a-shared-secret'),
        'click_count' => 0,
    ])->save();

    $body = (string) $this->actingAs(transferOperator())
        ->get('/api/v1/links/export')->assertOk()->getContent();

    expect($body)->toContain('guarded1')
        ->toContain('yes')
        ->not->toContain('a-shared-secret')
        ->not->toContain((string) $link->password_hash);
});

// --- 6.9 round trip ---

it('re-imports its own export', function (): void {
    $domain = transferDomain();
    $operator = transferOperator();

    foreach (['round001', 'round002'] as $slug) {
        $link = new Link;
        $link->forceFill([
            'public_id' => (string) Str::ulid(),
            'domain_id' => $domain->id,
            'slug' => $slug,
            'destination' => 'https://example.com/'.$slug,
            'created_by' => $operator->id,
            'click_count' => 0,
        ])->save();
    }

    $exported = (string) $this->actingAs($operator)->get('/api/v1/links/export')->getContent();

    // Onto a second domain, since the slugs are taken on the first. This is the
    // real case the format exists for: moving a corpus somewhere else.
    $elsewhere = Domain::factory()->create(['host' => 'other.example.test', 'verified_at' => now()]);

    $import = runImport($operator, $elsewhere, $exported);

    expect($import->created_rows)->toBe(2)
        ->and($import->failed_rows)->toBe(0)
        ->and(Link::query()->where('domain_id', $elsewhere->id)->count())->toBe(2);
});

// --- 6.10 progress ---

it('reports progress and hides an import from another account', function (): void {
    $domain = transferDomain();
    $operator = transferOperator();

    $import = runImport($operator, $domain, "destination,slug\nhttps://example.com/p,progres1\n");

    $this->actingAs($operator)
        ->getJson('/api/v1/links/imports/'.$import->public_id)
        ->assertOk()
        ->assertJsonPath('import.processed', 1)
        ->assertJsonPath('import.total', 1);

    $stranger = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($stranger)
        ->getJson('/api/v1/links/imports/'.$import->public_id)
        ->assertStatus(404);
});

it('returns the submitted rows with their outcomes beside them', function (): void {
    $domain = transferDomain();
    $operator = transferOperator();

    $import = runImport($operator, $domain, implode("\n", [
        'destination,slug',
        'https://example.com/ok,result01',
        'not-a-url,result02',
        '',
    ]));

    $body = (string) $this->actingAs($operator)
        ->get('/api/v1/links/imports/'.$import->public_id.'/result')
        ->assertOk()->getContent();

    expect($body)->toContain('outcome')
        ->toContain('created')
        ->toContain('refused')
        ->toContain('not-a-url');
});

it('queues the batch rather than processing it in the request', function (): void {
    Queue::fake();

    $domain = transferDomain();

    $this->actingAs(transferOperator())
        ->post('/api/v1/links/import', [
            'file' => csvFile("destination,slug\nhttps://example.com/q,queued01\n"),
            'domain' => $domain->public_id,
        ])
        ->assertStatus(202);

    Queue::assertPushed(ProcessLinkImport::class);

    // Nothing created yet: the request returned before the work started.
    expect(Link::query()->where('slug', 'queued01')->exists())->toBeFalse();
});
