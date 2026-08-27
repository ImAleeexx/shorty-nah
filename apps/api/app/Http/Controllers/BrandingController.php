<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Branding\BrandingAssetStore;
use App\Branding\BrandingBounds;
use App\Branding\BrandingException;
use App\Branding\ContrastCheck;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class BrandingController
{
    public function show(Request $request, SettingsStore $settings): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse([
            'branding' => $this->present($settings),
            'bounds' => [
                'radius' => ['min' => BrandingBounds::RADIUS_MIN, 'max' => BrandingBounds::RADIUS_MAX],
                'typefaces' => BrandingBounds::typefaces(),
                'max_asset_bytes' => BrandingAssetStore::MAX_BYTES,
                'max_asset_dimension' => BrandingAssetStore::MAX_DIMENSION,
            ],
        ]);
    }

    public function update(Request $request, SettingsStore $settings, AuditLog $audit): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'accent' => ['sometimes', 'string', 'max:64'],
            'radius' => ['sometimes', 'integer'],
            'typeface' => ['sometimes', 'string', 'max:64'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $errors = [];

        if (isset($input['accent']) && is_string($input['accent'])) {
            if (! BrandingBounds::permitsAccent($input['accent'])) {
                $errors['accent'] = 'An accent must be an OKLCH colour, for example oklch(0.55 0.16 250).';
            }
        }

        if (isset($input['radius']) && is_int($input['radius'])) {
            if (! BrandingBounds::permitsRadius($input['radius'])) {
                $errors['radius'] = sprintf(
                    'A corner radius must be between %d and %d pixels.',
                    BrandingBounds::RADIUS_MIN,
                    BrandingBounds::RADIUS_MAX,
                );
            }
        }

        if (isset($input['typeface']) && is_string($input['typeface'])) {
            if (! BrandingBounds::permitsTypeface($input['typeface'])) {
                $errors['typeface'] = 'Choose one of: '.implode(', ', array_keys(BrandingBounds::typefaces())).'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $changes = [];

        foreach ([
            'name' => 'instance.name',
            'accent' => 'branding.accent',
            'radius' => 'branding.radius',
            'typeface' => 'branding.typeface',
            'footer_text' => 'branding.footer_text',
        ] as $field => $key) {
            if (array_key_exists($field, $input)) {
                /** @var string|int $value */
                $value = $input[$field];
                $changes[$key] = $value;
            }
        }

        $settings->setMany($changes);

        $audit->record(
            AuditAction::BrandingChanged,
            actor: $request->user(),
            targetType: 'settings',
            context: ['keys' => implode(',', array_keys($changes))],
            request: $request,
        );

        $accent = $settings->string('branding.accent') ?? '';

        return new JsonResponse([
            'branding' => $this->present($settings),
            // A warning, not a refusal: the operator may have a reason, and being
            // told which mode fails is more useful than being blocked.
            'contrast' => ContrastCheck::assess($accent),
        ]);
    }

    public function upload(Request $request, SettingsStore $settings, BrandingAssetStore $assets): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        /** @var array{kind: string} $input */
        $input = $request->validate([
            'kind' => ['required', 'string', 'in:logo,wordmark,favicon'],
            'asset' => ['required', 'file'],
        ]);

        $file = $request->file('asset');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['asset' => 'No file was received.']);
        }

        try {
            $path = $assets->store($file, $input['kind']);
        } catch (BrandingException $e) {
            throw ValidationException::withMessages(['asset' => $e->getMessage()]);
        }

        $key = 'branding.'.$input['kind'].'_path';

        // The replaced asset is removed: leaving it would accumulate files nothing
        // references.
        $assets->forget($settings->string($key));

        $settings->set($key, $path);

        return new JsonResponse(['branding' => $this->present($settings)], 201);
    }

    private function administrates(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->administrates();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SettingsStore $settings): array
    {
        return [
            'name' => $settings->string('instance.name'),
            'accent' => $settings->string('branding.accent'),
            'radius' => $settings->integer('branding.radius'),
            'typeface' => $settings->string('branding.typeface'),
            'logo' => $settings->string('branding.logo_path'),
            'wordmark' => $settings->string('branding.wordmark_path'),
            'favicon' => $settings->string('branding.favicon_path'),
            'footer_text' => $settings->string('branding.footer_text'),
        ];
    }
}
