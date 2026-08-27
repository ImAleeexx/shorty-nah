<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The events an endpoint may subscribe to.
 *
 * A closed set, because every one of these has a payload shape an operator's
 * receiver depends on. Adding an event is a deliberate act with a documented
 * payload, not something that happens by accident.
 */
enum WebhookEvent: string
{
    case ClickRecorded = 'click.recorded';
    case LinkCreated = 'link.created';
    case LinkUpdated = 'link.updated';
    case LinkDeleted = 'link.deleted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
