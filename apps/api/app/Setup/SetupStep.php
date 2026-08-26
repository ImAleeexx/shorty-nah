<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * The wizard's steps, in the order an operator walks them.
 *
 * Declaration order is the contract: progress, resumption and the "previous
 * steps must be done first" guard all read it, so inserting a step here inserts
 * it everywhere.
 */
enum SetupStep: string
{
    case Connectivity = 'connectivity';
    case Administrator = 'administrator';
    case Instance = 'instance';
    case Branding = 'branding';
    case Analytics = 'analytics';
    case Registration = 'registration';
    case Mail = 'mail';

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        return self::cases();
    }

    /**
     * Steps that must already be complete before this one may be submitted.
     *
     * @return list<self>
     */
    public function predecessors(): array
    {
        $before = [];

        foreach (self::ordered() as $step) {
            if ($step === $this) {
                break;
            }

            $before[] = $step;
        }

        return $before;
    }

    public function skippable(): bool
    {
        // Outbound mail is the only optional one: an instance with no SMTP host
        // still works, it just cannot send invitations or password resets.
        return $this === self::Mail;
    }
}
