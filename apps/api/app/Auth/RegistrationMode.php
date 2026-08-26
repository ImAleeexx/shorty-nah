<?php

declare(strict_types=1);

namespace App\Auth;

enum RegistrationMode: string
{
    case Closed = 'closed';
    case Invite = 'invite';
    case Open = 'open';

    public function allowsSelfRegistration(): bool
    {
        return $this === self::Open;
    }

    public function requiresInvitation(): bool
    {
        return $this === self::Invite;
    }
}
