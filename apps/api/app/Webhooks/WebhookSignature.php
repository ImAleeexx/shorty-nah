<?php

declare(strict_types=1);

namespace App\Webhooks;

/**
 * How a receiver knows a delivery came from this instance.
 *
 * An HMAC over the timestamp and the exact request body, with the timestamp
 * inside the signed material rather than only beside it — signing the body alone
 * leaves a delivery replayable forever by anyone who captured one.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Shortynah-Signature';

    public const TIMESTAMP_HEADER = 'X-Shortynah-Timestamp';

    public static function compute(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
