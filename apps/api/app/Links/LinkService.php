<?php

declare(strict_types=1);

namespace App\Links;

use App\Domains\DomainService;
use App\Enums\RedirectMode;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creates and edits links.
 *
 * Every field that reaches the database passes through here, which is why the
 * model itself has no fillable attributes: a request body can never set an owner,
 * a domain, or a slug directly.
 */
final class LinkService
{
    public function __construct(
        private readonly SlugGenerator $slugs,
        private readonly DestinationValidator $destinations,
        private readonly DomainService $domains,
        private readonly SettingsStore $settings,
    ) {}

    /**
     * @param  array{
     *     destination: string,
     *     domain?: Domain|null,
     *     slug?: string|null,
     *     redirect_mode?: string|null,
     *     password?: string|null,
     *     expires_at?: string|null,
     *     max_clicks?: int|null,
     *     tags?: list<string>,
     * }  $input
     */
    public function create(array $input, User $owner): Link
    {
        $domain = $input['domain'] ?? $this->domains->primary();

        if (! $domain instanceof Domain) {
            throw new LinkException('No domain is configured to serve links.');
        }

        if (! $domain->servesLinks()) {
            throw new LinkException('That domain is not verified, so it cannot serve links yet.');
        }

        $destination = $this->destinations->validate($input['destination']);
        $slug = $this->resolveSlug($domain, $input['slug'] ?? null);

        $link = new Link;

        $link->forceFill([
            'domain_id' => $domain->id,
            'slug' => $slug,
            'destination' => $destination,
            'redirect_mode' => $this->resolveMode($input['redirect_mode'] ?? null),
            'password_hash' => $this->resolvePassword($input['password'] ?? null),
            'expires_at' => $this->resolveExpiry($input['expires_at'] ?? null),
            'max_clicks' => $this->resolveMaxClicks($input['max_clicks'] ?? null),
            'created_by' => $owner->id,
        ])->save();

        $this->syncTags($link, $input['tags'] ?? []);

        return $link->refresh();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Link $link, array $input): Link
    {
        $changes = [];

        if (array_key_exists('destination', $input) && is_string($input['destination'])) {
            $changes['destination'] = $this->destinations->validate($input['destination']);
        }

        if (array_key_exists('slug', $input) && is_string($input['slug'])) {
            $domain = $link->domain;

            if (! $domain instanceof Domain) {
                throw new LinkException('That link has no domain to check the slug against.');
            }

            $changes['slug'] = $this->resolveSlug($domain, $input['slug'], $link);
        }

        if (array_key_exists('redirect_mode', $input)) {
            $mode = $input['redirect_mode'];
            $changes['redirect_mode'] = $this->resolveMode(is_string($mode) ? $mode : null);
        }

        if (array_key_exists('expires_at', $input)) {
            $expiry = $input['expires_at'];
            $changes['expires_at'] = $this->resolveExpiry(is_string($expiry) ? $expiry : null);
        }

        if (array_key_exists('max_clicks', $input)) {
            $limit = $input['max_clicks'];
            $changes['max_clicks'] = $this->resolveMaxClicks(is_int($limit) ? $limit : null);
        }

        if (array_key_exists('password', $input)) {
            $password = $input['password'];
            $changes['password_hash'] = $this->resolvePassword(is_string($password) ? $password : null);
        }

        if (array_key_exists('disabled', $input)) {
            $changes['disabled_at'] = $input['disabled'] === true ? Carbon::now() : null;
        }

        if ($changes !== []) {
            $link->forceFill($changes)->save();
        }

        if (array_key_exists('tags', $input) && is_array($input['tags'])) {
            /** @var list<string> $tags */
            $tags = array_values(array_filter($input['tags'], is_string(...)));
            $this->syncTags($link, $tags);
        }

        return $link->refresh();
    }

    /**
     * The mode a link actually redirects with: its own choice, or the instance
     * default when it never made one.
     */
    public function effectiveMode(Link $link): RedirectMode
    {
        return $link->redirect_mode ?? $this->instanceDefaultMode();
    }

    public function instanceDefaultMode(): RedirectMode
    {
        return RedirectMode::tryFrom((string) $this->settings->get('redirect.default_mode'))
            ?? RedirectMode::Direct;
    }

    private function resolveSlug(Domain $domain, ?string $requested, ?Link $existing = null): string
    {
        if ($requested === null || trim($requested) === '') {
            return $this->slugs->generateFor($domain);
        }

        $slug = trim($requested);

        // Reserved words are checked first so the message is accurate. Several of
        // them are shorter than the minimum length or contain characters outside
        // the alphabet, and would otherwise be refused for the wrong reason.
        if (ReservedSlugs::contains($slug)) {
            throw new LinkException('That slug is reserved by the application.');
        }

        if (mb_strlen($slug) < SlugAlphabet::MIN_LENGTH || mb_strlen($slug) > SlugAlphabet::MAX_LENGTH) {
            throw new LinkException(sprintf(
                'A slug must be between %d and %d characters.',
                SlugAlphabet::MIN_LENGTH,
                SlugAlphabet::MAX_LENGTH,
            ));
        }

        if (! SlugAlphabet::permitsCustom($slug)) {
            throw new LinkException('A slug may use only '.SlugAlphabet::CUSTOM_DESCRIPTION.'.');
        }

        $excludedKey = $existing?->getKey();

        $taken = Link::withTrashed()
            ->where('domain_id', $domain->id)
            ->where('slug', $slug)
            ->when($excludedKey !== null, fn (Builder $query) => $query->whereKeyNot($excludedKey))
            ->exists();

        if ($taken) {
            throw new LinkException('That slug is already in use on this domain.');
        }

        return $slug;
    }

    private function resolveMode(?string $mode): ?string
    {
        if ($mode === null || $mode === '') {
            // Null is meaningful: the link follows the instance default, and moves
            // with it when the operator changes it.
            return null;
        }

        $resolved = RedirectMode::tryFrom($mode);

        if ($resolved === null) {
            throw new LinkException('The redirect mode must be direct or interstitial.');
        }

        return $resolved->value;
    }

    private function resolvePassword(?string $password): ?string
    {
        if ($password === null || $password === '') {
            return null;
        }

        return Hash::make($password);
    }

    private function resolveExpiry(?string $expiry): ?Carbon
    {
        if ($expiry === null || $expiry === '') {
            return null;
        }

        $parsed = Carbon::parse($expiry);

        if ($parsed->isPast()) {
            throw new LinkException('An expiry must be in the future.');
        }

        return $parsed;
    }

    private function resolveMaxClicks(?int $limit): ?int
    {
        if ($limit === null) {
            return null;
        }

        if ($limit < 1) {
            throw new LinkException('A click limit must be at least 1.');
        }

        return $limit;
    }

    /**
     * @param  list<string>  $names
     */
    private function syncTags(Link $link, array $names): void
    {
        $ids = [];

        foreach ($names as $name) {
            $normalised = Tag::normalise($name);

            if ($normalised === '' || mb_strlen($normalised) > 64) {
                continue;
            }

            $ids[] = DB::table('tags')->where('name', $normalised)->value('id')
                ?? DB::table('tags')->insertGetId([
                    'name' => $normalised,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        }

        $link->tags()->sync(array_values(array_unique($ids)));
    }
}
