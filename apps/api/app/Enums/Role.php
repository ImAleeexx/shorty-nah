<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles are ordered, so "may grant" and "may act on" reduce to a comparison.
 * The ordering is what prevents an administrator from creating an owner.
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 400,
            self::Admin => 300,
            self::Member => 200,
            self::Viewer => 100,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /**
     * An account may never grant a role above its own, so the owner role is
     * reachable only from an existing owner.
     */
    public function mayGrant(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function administrates(): bool
    {
        return $this->atLeast(self::Admin);
    }

    public function mayWrite(): bool
    {
        return $this->atLeast(self::Member);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
