<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Branding\QrRenderer;
use App\Models\Link;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A link's QR code, rendered on request rather than stored.
 *
 * Nothing is cached to disk: the code is derived entirely from the short URL and
 * the instance accent, both of which can change, and a stored image is a stale
 * image waiting to be served after a rebrand.
 */
final class QrCodeController
{
    public function show(Request $request, string $publicId, QrRenderer $renderer): SymfonyResponse
    {
        $link = $this->findVisible($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        $format = $request->query('format') === 'svg' ? 'svg' : 'png';

        $code = $renderer->render($this->shortUrl($link), $format);

        $response = new Response($code->body, 200, [
            'Content-Type' => $code->contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s.%s"', $link->slug, $code->extension),
            // The accent can change without the URL changing, so a cached code
            // outlives the branding it was drawn in.
            'Cache-Control' => 'no-store',
            // Reported in a header rather than only in the body, because the body
            // is an image and the interface still has to be able to say why the
            // operator's accent was not used.
            'X-Qr-Fallback' => $code->usedFallback ? 'ink' : 'accent',
        ]);

        return $response;
    }

    private function shortUrl(Link $link): string
    {
        $domain = $link->domain;
        $host = $domain === null ? '' : $domain->host;

        return sprintf(
            'https://%s/%s?%s=%s',
            $host,
            $link->slug,
            RedirectController::SCAN_PARAMETER,
            RedirectController::SCAN_VALUE,
        );
    }

    private function findVisible(Request $request, string $publicId): ?Link
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return null;
        }

        $link = Link::query()
            ->with('domain')
            ->visibleTo($actor)
            ->where('public_id', $publicId)
            ->first();

        return $link instanceof Link ? $link : null;
    }
}
