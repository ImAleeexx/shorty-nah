<?php

declare(strict_types=1);

namespace App\Links;

use App\Enums\RuleKind;

/**
 * Decides where a visitor goes.
 *
 * First match wins, in explicit position order. There is no weighting and no
 * randomness: an operator reading a link's rules top to bottom must be able to
 * say where any given visitor lands, and a rule set that cannot be reasoned about
 * is worse than no rules at all.
 *
 * A rule that cannot be evaluated does not match. That matters most for country
 * on an instance with no geographic databases: silently capturing traffic with a
 * condition nobody can check would be the wrong failure.
 */
final class RuleEvaluator
{
    /**
     * @param  list<RoutingRule>  $rules
     */
    public function destinationFor(array $rules, RoutingContext $context, string $fallback): string
    {
        foreach ($rules as $rule) {
            if ($this->matches($rule, $context)) {
                return $rule->destination;
            }
        }

        return $fallback;
    }

    private function matches(RoutingRule $rule, RoutingContext $context): bool
    {
        return match ($rule->kind) {
            RuleKind::Country => $this->matchesCountry($rule->value, $context->countryCode),
            RuleKind::Device => $this->matchesDevice($rule->value, $context->deviceType),
            RuleKind::Language => $this->matchesLanguage($rule->value, $context->languages),
            RuleKind::TimeWindow => $this->matchesWindow($rule->value, $context->minutesSinceMidnight),
        };
    }

    /**
     * A comma-separated list, so one rule can name several countries without
     * repeating its destination.
     */
    private function matchesCountry(string $value, string $countryCode): bool
    {
        if ($countryCode === '') {
            return false;
        }

        foreach (explode(',', mb_strtoupper($value)) as $candidate) {
            if (trim($candidate) === mb_strtoupper($countryCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * An operator writes `mobile`, not `smartphone`.
     *
     * The user-agent library speaks a finer vocabulary than a routing rule needs
     * — smartphone, phablet, feature phone, tablet, console, tv, wearable — and
     * making an operator learn it would be a rule set that fails silently when
     * someone writes the obvious word. Three classes, and anything that is
     * neither a phone nor a tablet is a desktop as far as a rule is concerned.
     */
    private function matchesDevice(string $value, string $deviceType): bool
    {
        if ($deviceType === '') {
            return false;
        }

        return mb_strtolower(trim($value)) === self::deviceClass($deviceType);
    }

    public static function deviceClass(string $deviceType): string
    {
        return match (mb_strtolower(trim($deviceType))) {
            'smartphone', 'phablet', 'feature phone' => 'mobile',
            'tablet' => 'tablet',
            default => 'desktop',
        };
    }

    /**
     * The values a rule may be written against.
     *
     * @return list<string>
     */
    public static function deviceClasses(): array
    {
        return ['mobile', 'tablet', 'desktop'];
    }

    /**
     * Matched on the primary subtag, so a rule for `es` matches `es-ES` and
     * `es-419`. An operator writing `es` means Spanish, not one region's Spanish.
     *
     * @param  list<string>  $languages
     */
    private function matchesLanguage(string $value, array $languages): bool
    {
        $wanted = mb_strtolower(trim(explode('-', trim($value))[0]));

        if ($wanted === '') {
            return false;
        }

        foreach ($languages as $language) {
            if (mb_strtolower(explode('-', $language)[0]) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * `HH:MM-HH:MM` in the instance reporting timezone.
     *
     * A window whose end is earlier than its start crosses midnight, and is
     * treated as such rather than as an empty window — 22:00-06:00 is the obvious
     * way to write "overnight" and refusing it would be pedantry.
     */
    private function matchesWindow(string $value, int $minutes): bool
    {
        $parts = explode('-', trim($value));

        if (count($parts) !== 2) {
            return false;
        }

        $start = $this->minutes($parts[0]);
        $end = $this->minutes($parts[1]);

        if ($start === null || $end === null) {
            return false;
        }

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $minutes >= $start && $minutes < $end
            : $minutes >= $start || $minutes < $end;
    }

    private function minutes(string $clock): ?int
    {
        $parts = explode(':', trim($clock));

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }
}
