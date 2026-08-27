<?php

declare(strict_types=1);

namespace App\Enums;

enum RedirectMode: string
{
    /** A plain HTTP redirect. The fast path, and the least observable. */
    case Direct = 'direct';

    /** A branded hold page whose beacon reports client-side signals. */
    case Interstitial = 'interstitial';

    /**
     * The interstitial's measurement without its page.
     *
     * The visitor sees no hold and no branding — the document exists only long
     * enough to report what a server cannot see, then navigates. It is slower
     * than a direct redirect by one page load, and that is the whole trade:
     * screen, viewport, timezone and language in exchange for a moment.
     */
    case Invisible = 'invisible';

    /** Whether this mode renders a document rather than answering with a 302. */
    public function rendersPage(): bool
    {
        return $this !== self::Direct;
    }

    /** Whether the click is recorded against a token the beacon can attach to. */
    public function usesBeacon(): bool
    {
        return $this->rendersPage();
    }
}
