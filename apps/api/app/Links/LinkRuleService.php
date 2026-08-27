<?php

declare(strict_types=1);

namespace App\Links;

use App\Enums\RuleKind;
use App\Models\Link;
use App\Models\LinkRule;
use Illuminate\Support\Facades\DB;

/**
 * Writing a link's rules.
 *
 * Rules are replaced as a set rather than edited one at a time. Ordering is the
 * whole semantics here — first match wins — so an interface that reorders by
 * issuing five individual updates would spend most of its time in states that
 * violate the unique position constraint, and any one of those updates failing
 * would leave an order nobody asked for.
 */
final class LinkRuleService
{
    /**
     * More than this and a rule set stops being something an operator can read
     * top to bottom and predict, which is the property first-match-wins ordering
     * exists to give them.
     */
    public const MAX_PER_LINK = 20;

    public function __construct(private readonly DestinationValidator $destinations) {}

    /**
     * @param  list<array{kind: string, value: string, destination: string}>  $rules
     * @return list<LinkRule>
     */
    public function replace(Link $link, array $rules): array
    {
        if (count($rules) > self::MAX_PER_LINK) {
            throw new LinkException(sprintf(
                'A link may carry at most %d routing rules.',
                self::MAX_PER_LINK,
            ));
        }

        $prepared = [];

        foreach ($rules as $index => $rule) {
            $kind = RuleKind::tryFrom($rule['kind']);

            if ($kind === null) {
                throw new LinkException(sprintf(
                    'Rule %d: a rule matches on %s.',
                    $index + 1,
                    implode(', ', array_column(RuleKind::cases(), 'value')),
                ));
            }

            $value = trim($rule['value']);

            if ($value === '') {
                throw new LinkException(sprintf('Rule %d: %s needs a value.', $index + 1, $kind->label()));
            }

            $this->assertValue($kind, $value, $index + 1);

            // Through the same validator a link destination uses, so a rule
            // cannot become the way around a refusal that applies everywhere
            // else — loopback, private, link-local, CGNAT and metadata addresses
            // are all checked after resolution.
            $destination = $this->destinations->validate($rule['destination']);

            $prepared[] = [
                'position' => $index,
                'kind' => $kind,
                'value' => $value,
                'destination' => $destination,
            ];
        }

        return DB::transaction(function () use ($link, $prepared): array {
            // Deleted rather than reconciled: the unique position constraint makes
            // an in-place reorder a sequence of collisions, and a rule carries no
            // history worth preserving.
            LinkRule::query()->where('link_id', $link->id)->get()->each->delete();

            $written = [];

            foreach ($prepared as $rule) {
                $model = new LinkRule;
                $model->forceFill([
                    'link_id' => $link->id,
                    'position' => $rule['position'],
                    'kind' => $rule['kind'],
                    'value' => $rule['value'],
                    'destination' => $rule['destination'],
                ])->save();

                $written[] = $model;
            }

            return $written;
        });
    }

    private function assertValue(RuleKind $kind, string $value, int $ordinal): void
    {
        match ($kind) {
            RuleKind::Country => $this->assertCountries($value, $ordinal),
            RuleKind::Device => $this->assertDevice($value, $ordinal),
            RuleKind::Language => $this->assertLanguage($value, $ordinal),
            RuleKind::TimeWindow => $this->assertWindow($value, $ordinal),
        };
    }

    private function assertCountries(string $value, int $ordinal): void
    {
        foreach (explode(',', $value) as $code) {
            if (preg_match('/^[A-Za-z]{2}$/', trim($code)) !== 1) {
                throw new LinkException(sprintf(
                    'Rule %d: a country is a two-letter code, and several may be separated by commas.',
                    $ordinal,
                ));
            }
        }
    }

    private function assertDevice(string $value, int $ordinal): void
    {
        if (! in_array(mb_strtolower($value), RuleEvaluator::deviceClasses(), true)) {
            throw new LinkException(sprintf(
                'Rule %d: a device is one of %s.',
                $ordinal,
                implode(', ', RuleEvaluator::deviceClasses()),
            ));
        }
    }

    private function assertLanguage(string $value, int $ordinal): void
    {
        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $value) !== 1) {
            throw new LinkException(sprintf(
                'Rule %d: a language is a tag such as es or es-419.',
                $ordinal,
            ));
        }
    }

    private function assertWindow(string $value, int $ordinal): void
    {
        $parts = explode('-', $value);

        $valid = count($parts) === 2
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($parts[0])) === 1
            && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim($parts[1])) === 1
            && trim($parts[0]) !== trim($parts[1]);

        if (! $valid) {
            throw new LinkException(sprintf(
                'Rule %d: a time window is written HH:MM-HH:MM, and its ends must differ.',
                $ordinal,
            ));
        }
    }
}
