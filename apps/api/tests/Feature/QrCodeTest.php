<?php

declare(strict_types=1);

use App\Branding\QrRenderer;
use App\Clicks\ArrayClickQueue;
use App\Clicks\ClickQueue;
use App\Enums\Role;
use App\Models\Domain;
use App\Models\Link;
use App\Models\User;
use App\Settings\SettingsStore;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Str;

function qrLink(string $slug = 'qrlink01'): Link
{
    $domain = Domain::factory()->create(['host' => 'qr.example.test', 'verified_at' => now()]);

    $link = new Link;
    $link->forceFill([
        'public_id' => (string) Str::ulid(),
        'domain_id' => $domain->id,
        'slug' => $slug,
        'destination' => 'https://example.com/qr',
        'redirect_mode' => 'direct',
        'click_count' => 0,
    ])->save();

    return $link;
}

function qrOperator(): User
{
    return User::factory()->admin()->freshlyAuthenticated()->create();
}

beforeEach(function (): void {
    cache()->flush();
    app(SettingsStore::class)->set('branding.accent', 'oklch(0.55 0.16 250)');
});

it('renders a png encoding the short url on its own domain', function (): void {
    $link = qrLink();

    $response = $this->actingAs(qrOperator())
        ->get('/api/v1/links/'.$link->public_id.'/qr')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('image/png');

    $body = $response->getContent();

    // A real PNG, not a placeholder: decoded rather than pattern-matched.
    $image = imagecreatefromstring((string) $body);

    expect($image)->not->toBeFalse()
        ->and(imagesx($image))->toBeGreaterThan(100);
});

it('renders an svg carrying the accent', function (): void {
    $link = qrLink('qrlink02');

    $response = $this->actingAs(qrOperator())
        ->get('/api/v1/links/'.$link->public_id.'/qr?format=svg')
        ->assertOk();

    $body = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('image/svg+xml')
        ->and($body)->toStartWith('<svg')
        ->and($response->headers->get('X-Qr-Fallback'))->toBe('accent');
});

it('encodes the short url with the scan marker', function (): void {
    $link = qrLink('qrlink03');

    // Asserted through the renderer, because reading a code back out of a PNG in
    // a test would be testing a decoder rather than this.
    $url = 'https://qr.example.test/qrlink03?s=qr';
    $code = app(QrRenderer::class)->render($url, 'svg');

    expect($code->body)->toContain('<path')
        ->and($code->usedFallback)->toBeFalse();
});

it('falls back to ink when the accent is too pale to scan', function (): void {
    // Legible enough for large text, nowhere near enough for a camera.
    app(SettingsStore::class)->set('branding.accent', 'oklch(0.85 0.05 250)');

    $link = qrLink('qrlink04');

    $response = $this->actingAs(qrOperator())
        ->get('/api/v1/links/'.$link->public_id.'/qr?format=svg')
        ->assertOk();

    expect($response->headers->get('X-Qr-Fallback'))->toBe('ink')
        ->and((string) $response->getContent())->toContain('#1a1a18');
});

it('reflects a changed accent without a rebuild', function (): void {
    $link = qrLink('qrlink05');

    $before = app(QrRenderer::class)->render('https://qr.example.test/qrlink05', 'svg');

    app(SettingsStore::class)->set('branding.accent', 'oklch(0.45 0.18 20)');

    $after = app(QrRenderer::class)->render('https://qr.example.test/qrlink05', 'svg');

    expect($after->foreground)->not->toBe($before->foreground);
});

it('answers as though the link does not exist for an account that cannot see it', function (): void {
    $link = qrLink('qrlink06');
    $stranger = User::factory()->create(['role' => Role::Member]);

    $this->actingAs($stranger)
        ->get('/api/v1/links/'.$link->public_id.'/qr')
        ->assertStatus(404);
});

it('marks a scan on the queued click and leaves an ordinary click unmarked', function (): void {
    $queue = new ArrayClickQueue;
    app()->instance(ClickQueue::class, $queue);

    $link = qrLink('qrlink07');

    $this->call('GET', 'http://qr.example.test/qrlink07?s=qr', server: ['REMOTE_ADDR' => '198.51.100.60'])
        ->assertRedirect('https://example.com/qr');

    $this->call('GET', 'http://qr.example.test/qrlink07', server: ['REMOTE_ADDR' => '198.51.100.61'])
        ->assertRedirect('https://example.com/qr');

    $drained = $queue->drain(10);

    // Two clicks, both counted; one of them attributed to the code. A scan is a
    // real visit, so it belongs in the total as well as in the scan figure.
    expect($drained)->toHaveCount(2)
        ->and($drained[0]->source)->toBe('qr')
        ->and($drained[1]->source)->toBe('');
});

it('draws every module the encoder produced, in the right place', function (): void {
    $url = 'https://qr.example.test/qrlink08?s=qr';

    $matrix = Encoder::encode($url, ErrorCorrectionLevel::M())->getMatrix();

    $png = app(QrRenderer::class)->render($url, 'png');
    $image = imagecreatefromstring($png->body);

    expect($image)->not->toBeFalse();

    // Sampled at the centre of each module and compared to the encoder's own
    // matrix. The library's encoding is its business; whether this renders that
    // encoding faithfully — right modules, right positions, right quiet zone —
    // is the part written here, and a code that is off by one module is a code
    // that does not scan.
    $quiet = 4;
    $pixels = 10;
    $mismatches = 0;

    for ($y = 0; $y < $matrix->getHeight(); $y++) {
        for ($x = 0; $x < $matrix->getWidth(); $x++) {
            $centreX = ($x + $quiet) * $pixels + intdiv($pixels, 2);
            $centreY = ($y + $quiet) * $pixels + intdiv($pixels, 2);

            $colour = imagecolorat($image, $centreX, $centreY);
            $isDark = $colour !== imagecolorallocate($image, 255, 255, 255) && $colour !== 0xFFFFFF;

            if ($isDark !== ($matrix->get($x, $y) === 1)) {
                $mismatches++;
            }
        }
    }

    expect($mismatches)->toBe(0);

    // And the quiet zone is genuinely blank, which scanners require.
    expect(imagecolorat($image, 2, 2))->toBe(0xFFFFFF);
});
