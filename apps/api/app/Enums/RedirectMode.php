<?php

declare(strict_types=1);

namespace App\Enums;

enum RedirectMode: string
{
    /** A plain HTTP redirect. The fast path, and the least observable. */
    case Direct = 'direct';

    /** A branded hold page whose beacon reports client-side signals. */
    case Interstitial = 'interstitial';
}
