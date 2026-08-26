<?php

declare(strict_types=1);

namespace App\Links;

/**
 * Slugs that would shadow a route the instance needs.
 *
 * A link at /api or /setup would make part of the product unreachable on that
 * domain, so these are refused for both generated and custom slugs.
 */
final class ReservedSlugs
{
    /**
     * @var list<string>
     */
    private const RESERVED = [
        // Application and framework paths
        'api', 'sanctum', 'horizon', 'storage', 'up', 'health', 'telescope',
        // Interface routes
        'setup', 'login', 'logout', 'register', 'signin', 'signup', 'dashboard',
        'settings', 'account', 'admin', 'links', 'link', 'analytics', 'domains',
        'users', 'invitations', 'tokens', 'profile', 'password', 'verify',
        // Framework and crawler conventions
        '_next', 'static', 'assets', 'public', 'favicon.ico', 'robots.txt',
        'sitemap.xml', 'manifest.json', 'well-known',
        // Reserved for the redirect path's own surfaces
        'expired', 'disabled', 'protected', 'preview', 'qr',
    ];

    public static function contains(string $slug): bool
    {
        // Compared case-insensitively even though slugs are case-sensitive: a
        // link at /API shadows /api just as effectively.
        return in_array(mb_strtolower($slug), self::RESERVED, true);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::RESERVED;
    }
}
