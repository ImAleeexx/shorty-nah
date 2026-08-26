<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clicks\ClickSignalStore;
use App\Clicks\ClickToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives the hold page's measurement.
 *
 * Accepts nothing without a valid, unredeemed token, so a caller cannot attribute
 * signals to a click it did not make or resubmit one to inflate a figure. The
 * response body is always empty: this is a fire-and-forget beacon, and telling a
 * caller whether its token was accepted would turn the endpoint into an oracle.
 */
final class ClickBeaconController
{
    /**
     * Bounds on what a browser can plausibly report, so a hostile payload cannot
     * store arbitrary values.
     */
    private const MAX_DIMENSION = 32768;

    private const MAX_DWELL_MS = 600000;

    public function __invoke(Request $request, ClickToken $tokens, ClickSignalStore $signals): Response
    {
        $token = $request->input('token');

        if (is_string($token) && $token !== '') {
            $click = $tokens->redeem($token);

            if ($click !== null) {
                $signals->put($click->clickId, $this->sanitise($request, $click->linkId));
            }
        }

        // 204 regardless. A visitor is already navigating away and nothing here
        // should be observable.
        return new Response(status: 204, headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitise(Request $request, int $linkId): array
    {
        return [
            'link_id' => $linkId,
            'viewport_width' => $this->dimension($request->input('viewport_width')),
            'viewport_height' => $this->dimension($request->input('viewport_height')),
            'screen_width' => $this->dimension($request->input('screen_width')),
            'screen_height' => $this->dimension($request->input('screen_height')),
            'device_pixel_ratio' => $this->ratio($request->input('device_pixel_ratio')),
            'timezone' => $this->text($request->input('timezone'), 64),
            'language' => $this->text($request->input('language'), 32),
            'color_scheme' => $this->enum($request->input('color_scheme'), ['light', 'dark']),
            'connection_type' => $this->text($request->input('connection_type'), 16),
            'dwell_ms' => $this->bounded($request->input('dwell_ms'), 0, self::MAX_DWELL_MS),
            'reported_at' => now()->toIso8601String(),
        ];
    }

    private function dimension(mixed $value): ?int
    {
        return $this->bounded($value, 1, self::MAX_DIMENSION);
    }

    private function bounded(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number < $min || $number > $max ? null : $number;
    }

    private function ratio(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $ratio = (float) $value;

        return $ratio <= 0.0 || $ratio > 8.0 ? null : round($ratio, 2);
    }

    private function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Printable ASCII only: these values reach the event store and a report.
        $clean = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';

        return $clean === '' ? null : mb_substr($clean, 0, $maxLength);
    }

    /**
     * @param  list<string>  $permitted
     */
    private function enum(mixed $value, array $permitted): ?string
    {
        return is_string($value) && in_array($value, $permitted, true) ? $value : null;
    }
}
