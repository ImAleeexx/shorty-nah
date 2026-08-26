<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;

/**
 * Strips secrets and network addresses out of log context before anything is
 * written.
 *
 * Redaction is by key rather than by inspecting values: a value-shape heuristic
 * misses a credential that happens to look ordinary, and a key list is
 * something a reviewer can read and reason about.
 */
final class RedactSensitiveValues
{
    public const REDACTED = '[redacted]';

    /**
     * Matched against array keys and header names, case-insensitively.
     */
    private const SENSITIVE = '/(pass(word|wd)?|secret|token|api[_-]?key|authorization|cookie|credential|licen[cs]e|session[_-]?id|private[_-]?key|remember|otp|recovery[_-]?code)/i';

    /**
     * Raw addresses are not kept, even in diagnostics — analytics derives a
     * rotating hash instead, and a log is the obvious place that guarantee
     * leaks.
     */
    private const ADDRESS_KEYS = '/^(ip|ip_address|client_ip|remote_addr|x[_-]forwarded[_-]for)$/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrub(array $values, int $depth = 0): array
    {
        if ($depth > 12) {
            return $values;
        }

        $scrubbed = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && (preg_match(self::SENSITIVE, $key) === 1 || preg_match(self::ADDRESS_KEYS, $key) === 1)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }

            $scrubbed[$key] = is_array($value)
                ? $this->scrub($value, $depth + 1)
                : $value;
        }

        return $scrubbed;
    }
}
