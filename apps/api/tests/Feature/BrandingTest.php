<?php

declare(strict_types=1);

use App\Branding\BrandingBounds;
use App\Branding\ContrastCheck;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function administrator(): User
{
    return User::factory()->admin()->freshlyAuthenticated()->create();
}

/** A real encoded image, so validation is exercised rather than mocked. */
function imageFile(string $name, int $width = 320, int $height = 120, string $format = 'png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 160));

    $path = tempnam(sys_get_temp_dir(), 'branding').'.'.$format;

    match ($format) {
        'png' => imagepng($image, $path),
        'jpeg' => imagejpeg($image, $path),
        'webp' => imagewebp($image, $path),
        'gif' => imagegif($image, $path),
    };

    imagedestroy($image);

    return new UploadedFile($path, $name, null, null, true);
}

beforeEach(function (): void {
    Storage::fake('public');
    cache()->flush();
});

// --- 12.11 bounds ---

it('accepts an accent, radius and typeface within bounds', function (): void {
    $this->actingAs(administrator())->putJson('/api/v1/branding', [
        'name' => 'Externalia Links',
        'accent' => 'oklch(0.62 0.19 26)',
        'radius' => 12,
        'typeface' => 'geist',
    ])->assertOk()->assertJsonPath('branding.radius', 12);
});

it('refuses a radius outside the permitted range and states it', function (int $radius): void {
    $response = $this->actingAs(administrator())
        ->putJson('/api/v1/branding', ['radius' => $radius])
        ->assertStatus(422)
        ->assertJsonValidationErrors('radius');

    // An unbounded radius would let an operator reproduce the squircle look this
    // design rejects.
    expect($response->json('errors.radius.0'))->toContain((string) BrandingBounds::RADIUS_MIN)
        ->and($response->json('errors.radius.0'))->toContain((string) BrandingBounds::RADIUS_MAX);
})->with([0, 3, 15, 64, 9999]);

it('refuses a typeface outside the curated list and returns the choices', function (): void {
    $response = $this->actingAs(administrator())
        ->putJson('/api/v1/branding', ['typeface' => 'Inter'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('typeface');

    expect($response->json('errors.typeface.0'))->toContain('geist');
});

it('offers no banned typeface', function (): void {
    $offered = array_map('strtolower', array_keys(BrandingBounds::typefaces()));

    foreach (['inter', 'roboto', 'helvetica', 'open-sans', 'opensans', 'arial'] as $banned) {
        expect($offered)->not->toContain($banned);
    }
});

it('refuses an accent it cannot parse', function (string $accent): void {
    $this->actingAs(administrator())
        ->putJson('/api/v1/branding', ['accent' => $accent])
        ->assertStatus(422)
        ->assertJsonValidationErrors('accent');
})->with([
    'red',
    '#ff0000',
    'rgb(1 2 3)',
    'oklch(0.6 0.2)',
    'oklch(2 0.2 20)',
    'oklch(0.6 0.9 20)',
    'oklch(0.6 0.2 400)',
    'oklch(0.6 0.2 20); background: url(evil)',
]);

it('publishes its bounds so the interface can enforce the same ones', function (): void {
    $this->actingAs(administrator())->getJson('/api/v1/branding')
        ->assertOk()
        ->assertJsonPath('bounds.radius.min', BrandingBounds::RADIUS_MIN)
        ->assertJsonPath('bounds.radius.max', BrandingBounds::RADIUS_MAX)
        ->assertJsonStructure(['bounds' => ['typefaces', 'max_asset_bytes', 'max_asset_dimension']]);
});

it('hides branding settings from a non-administrator', function (): void {
    $this->actingAs(User::factory()->member()->freshlyAuthenticated()->create())
        ->putJson('/api/v1/branding', ['radius' => 10])
        ->assertStatus(404);
});

// --- 12.10 contrast ---

it('warns when an accent is unreadable in a mode', function (): void {
    // Pale enough to vanish on the light canvas.
    $verdict = ContrastCheck::assess('oklch(0.95 0.05 250)');

    expect($verdict['passes'])->toBeFalse()
        ->and($verdict['warning'])->toContain('light')
        ->and($verdict['light'])->toBeLessThan(ContrastCheck::MINIMUM);
});

it('warns when an accent is unreadable on the dark canvas', function (): void {
    $verdict = ContrastCheck::assess('oklch(0.2 0.1 250)');

    expect($verdict['passes'])->toBeFalse()
        ->and($verdict['warning'])->toContain('dark');
});

it('accepts an accent readable in both modes', function (): void {
    $verdict = ContrastCheck::assess('oklch(0.55 0.16 250)');

    expect($verdict['passes'])->toBeTrue()
        ->and($verdict['warning'])->toBeNull();
});

it('returns the contrast verdict when branding is saved', function (): void {
    $this->actingAs(administrator())
        ->putJson('/api/v1/branding', ['accent' => 'oklch(0.95 0.05 250)'])
        ->assertOk()
        ->assertJsonPath('contrast.passes', false);

    // A warning, not a refusal: the operator may have a reason, and being told
    // which mode fails is more useful than being blocked.
    expect(app(SettingsStore::class)->string('branding.accent'))->toBe('oklch(0.95 0.05 250)');
});

it('agrees with the interface on the contrast of a white-on-black extreme', function (): void {
    // Both implementations must agree; a conversion error shows up here.
    $verdict = ContrastCheck::assess('oklch(1 0 0)');

    expect($verdict['dark'])->toBeGreaterThan(14.0);
});

// --- 12.12 uploads ---

it('accepts a raster image and re-encodes it', function (): void {
    $response = $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => imageFile('logo.png'),
    ]);

    $response->assertCreated();

    $path = $response->json('branding.logo');

    expect($path)->toStartWith('/storage/branding/logo-')
        // Re-encoded, so nothing from the original file survives but its pixels.
        ->and($path)->toEndWith('.webp');

    Storage::disk('public')->assertExists(substr((string) $path, strlen('/storage/')));
});

it('refuses an SVG', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'svg').'.svg';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => new UploadedFile($path, 'logo.svg', 'image/svg+xml', null, true),
    ])->assertStatus(422)->assertJsonValidationErrors('asset');
});

it('refuses an SVG disguised as a PNG', function (): void {
    // The extension and the declared type both claim PNG. Only the contents tell
    // the truth.
    $path = tempnam(sys_get_temp_dir(), 'svg').'.png';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

    $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => new UploadedFile($path, 'logo.png', 'image/png', null, true),
    ])->assertStatus(422)->assertJsonValidationErrors('asset');
});

it('refuses a file that is not an image at all', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'txt').'.png';
    file_put_contents($path, str_repeat('not an image', 100));

    $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => new UploadedFile($path, 'logo.png', 'image/png', null, true),
    ])->assertStatus(422);
});

it('refuses an image beyond the pixel limit', function (): void {
    $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => imageFile('huge.png', 5000, 100),
    ])->assertStatus(422)->assertJsonValidationErrors('asset');
});

it('never uses the client filename as a path', function (): void {
    $response = $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => imageFile('../../etc/passwd.png'),
    ]);

    $response->assertCreated();

    expect($response->json('branding.logo'))->not->toContain('passwd')
        ->and($response->json('branding.logo'))->not->toContain('..');
});

it('removes the asset it replaced', function (): void {
    $admin = administrator();

    $first = $this->actingAs($admin)->post('/api/v1/branding/assets', [
        'kind' => 'logo', 'asset' => imageFile('one.png'),
    ])->json('branding.logo');

    $this->actingAs($admin)->post('/api/v1/branding/assets', [
        'kind' => 'logo', 'asset' => imageFile('two.png'),
    ])->assertCreated();

    // Leaving it would accumulate files nothing references.
    Storage::disk('public')->assertMissing(substr((string) $first, strlen('/storage/')));
});

it('accepts every permitted raster format', function (string $format): void {
    $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => imageFile("logo.{$format}", 200, 80, $format),
    ])->assertCreated();
})->with(['png', 'jpeg', 'webp', 'gif']);

it('accepts a palette image', function (): void {
    // GIFs and 8-bit PNGs are palette images, which the WebP encoder refuses
    // outright. PNG-8 is a common design-tool export, so this is not an edge case.
    $image = imagecreate(200, 80);
    imagecolorallocate($image, 40, 90, 160);

    $path = tempnam(sys_get_temp_dir(), 'palette').'.png';
    imagepng($image, $path);
    imagedestroy($image);

    expect(imageistruecolor(imagecreatefrompng($path)))->toBeFalse();

    $this->actingAs(administrator())->post('/api/v1/branding/assets', [
        'kind' => 'logo',
        'asset' => new UploadedFile($path, 'palette.png', 'image/png', null, true),
    ])->assertCreated();
});
