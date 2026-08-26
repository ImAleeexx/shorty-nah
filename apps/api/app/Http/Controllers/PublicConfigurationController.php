<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Settings\SettingsStore;
use Illuminate\Http\JsonResponse;

/**
 * The unauthenticated configuration the interface needs before anyone signs in.
 *
 * The response is assembled from the registry's exposed subset rather than
 * filtered after the fact: a new setting is private unless its definition says
 * otherwise, so adding one cannot leak it by omission.
 */
final class PublicConfigurationController
{
    public function __invoke(SettingsStore $settings): JsonResponse
    {
        $exposed = $settings->exposed();

        return new JsonResponse([
            'installed' => $settings->installed(),
            'instance' => [
                'name' => $exposed['instance.name'] ?? null,
            ],
            'registration' => [
                'mode' => $exposed['registration.mode'] ?? null,
            ],
            'branding' => [
                'accent' => $exposed['branding.accent'] ?? null,
                'radius' => $exposed['branding.radius'] ?? null,
                'typeface' => $exposed['branding.typeface'] ?? null,
                'logo' => $exposed['branding.logo_path'] ?? null,
                'wordmark' => $exposed['branding.wordmark_path'] ?? null,
                'favicon' => $exposed['branding.favicon_path'] ?? null,
            ],
        ]);
    }
}
