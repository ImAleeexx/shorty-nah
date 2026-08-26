<?php

declare(strict_types=1);

namespace App\Clicks;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ties a beacon submission to the click that produced it.
 *
 * A raw click identifier would be forgeable and replayable: anyone could post
 * client signals for a click they never made, or resubmit the same one to inflate
 * a figure. The token is signed so it cannot be minted, carries an expiry so a
 * stale page cannot report hours later, and is redeemable once.
 */
final class ClickToken
{
    private const REDEMPTION_PREFIX = 'shortynah:click-token:';

    /**
     * Long enough for a visitor on a slow connection to finish loading and
     * report, short enough that a page left open overnight cannot.
     */
    public const LIFETIME_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $key,
    ) {}

    /**
     * @return array{token: string, click_id: string}
     */
    public function issue(int $linkId): array
    {
        $clickId = (string) Str::ulid();
        $expiresAt = Carbon::now()->addSeconds(self::LIFETIME_SECONDS)->getTimestamp();

        $payload = $linkId.'.'.$clickId.'.'.$expiresAt;

        return [
            'token' => $payload.'.'.$this->sign($payload),
            'click_id' => $clickId,
        ];
    }

    /**
     * Returns the click this token stands for, or null if it was forged, has
     * expired, or has already been redeemed.
     */
    public function redeem(string $token): ?RedeemedClick
    {
        $parts = explode('.', $token);

        if (count($parts) !== 4) {
            return null;
        }

        [$linkId, $clickId, $expiresAt, $signature] = $parts;

        if (! hash_equals($this->sign($linkId.'.'.$clickId.'.'.$expiresAt), $signature)) {
            return null;
        }

        if (! ctype_digit($linkId) || ! ctype_digit($expiresAt)) {
            return null;
        }

        if ((int) $expiresAt <= Carbon::now()->getTimestamp()) {
            return null;
        }

        // add() is atomic: the first caller to claim the key wins, so a replay
        // loses even when it arrives concurrently.
        $claimed = $this->cache->add(
            self::REDEMPTION_PREFIX.$clickId,
            true,
            self::LIFETIME_SECONDS,
        );

        if (! $claimed) {
            return null;
        }

        return new RedeemedClick((int) $linkId, $clickId);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key);
    }
}
