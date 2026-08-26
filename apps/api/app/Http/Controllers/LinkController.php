<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Links\LinkException;
use App\Links\LinkService;
use App\Links\SlugExhaustedException;
use App\Models\Domain;
use App\Models\Link;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class LinkController
{
    public function index(Request $request, LinkService $links): JsonResponse
    {
        $actor = $this->actor($request);

        /** @var array{search?: string, domain?: string, owner?: string, tag?: string, per_page?: int} $filters */
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'size:26'],
            'owner' => ['nullable', 'string', 'size:26'],
            'tag' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Link::query()
            ->with(['domain', 'tags'])
            ->visibleTo($actor)
            ->orderByDesc('created_at');

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = $filters['search'];

            $query->where(function ($inner) use ($term): void {
                $inner->where('slug', 'like', "%{$term}%")
                    ->orWhere('destination', 'like', "%{$term}%")
                    ->orWhereHas('tags', fn ($tags) => $tags->where('name', 'like', '%'.Tag::normalise($term).'%'));
            });
        }

        if (isset($filters['domain'])) {
            $domain = Domain::query()->where('public_id', $filters['domain'])->first();

            // Zero is never a real key, so a filter naming an unknown domain
            // matches nothing rather than silently matching everything.
            $query->where('domain_id', $domain instanceof Domain ? $domain->id : 0);
        }

        if (isset($filters['owner'])) {
            $owner = User::query()->where('public_id', $filters['owner'])->first();
            $query->where('created_by', $owner instanceof User ? $owner->id : 0);
        }

        if (isset($filters['tag'])) {
            $tag = Tag::normalise($filters['tag']);
            $query->whereHas('tags', fn ($tags) => $tags->where('name', $tag));
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return new JsonResponse([
            'links' => collect($page->items())->map(fn (Link $link): array => $this->present($link, $links)),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $publicId, LinkService $links): JsonResponse
    {
        $link = $this->findVisible($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse(['link' => $this->present($link, $links)]);
    }

    public function store(Request $request, LinkService $links): JsonResponse
    {
        $actor = $this->actor($request);

        if (! $actor->mayWrite()) {
            return new JsonResponse(['message' => 'This account cannot create links.'], 403);
        }

        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'destination' => ['required', 'string', 'max:2048'],
            'domain' => ['nullable', 'string', 'size:26'],
            'slug' => ['nullable', 'string', 'max:64'],
            'redirect_mode' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'max_clicks' => ['nullable', 'integer', 'min:1'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:64'],
        ]);

        try {
            $link = $links->create([
                'destination' => (string) $input['destination'],
                'domain' => $this->resolveDomain($input['domain'] ?? null),
                'slug' => isset($input['slug']) ? (string) $input['slug'] : null,
                'redirect_mode' => isset($input['redirect_mode']) ? (string) $input['redirect_mode'] : null,
                'password' => isset($input['password']) ? (string) $input['password'] : null,
                'expires_at' => isset($input['expires_at']) ? (string) $input['expires_at'] : null,
                'max_clicks' => isset($input['max_clicks']) ? (int) $input['max_clicks'] : null,
                'tags' => $this->tagList($input['tags'] ?? null),
            ], $actor);
        } catch (SlugExhaustedException $e) {
            // Distinct from a validation failure: nothing the caller sent is
            // wrong, the instance has run out of room at its configured length.
            return new JsonResponse(['message' => $e->getMessage()], 503);
        } catch (LinkException $e) {
            throw ValidationException::withMessages(['destination' => $e->getMessage()]);
        }

        return new JsonResponse(['link' => $this->present($link, $links)], 201);
    }

    public function update(Request $request, string $publicId, LinkService $links): JsonResponse
    {
        $actor = $this->actor($request);
        $link = $this->findVisible($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        if (! $actor->mayWrite()) {
            return new JsonResponse(['message' => 'This account cannot edit links.'], 403);
        }

        /** @var array<string, mixed> $input */
        $input = $request->validate([
            'destination' => ['sometimes', 'string', 'max:2048'],
            'slug' => ['sometimes', 'string', 'max:64'],
            'redirect_mode' => ['sometimes', 'nullable', 'string'],
            'password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'max_clicks' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'disabled' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:64'],
        ]);

        try {
            $link = $links->update($link, $input);
        } catch (LinkException $e) {
            throw ValidationException::withMessages(['destination' => $e->getMessage()]);
        }

        return new JsonResponse(['link' => $this->present($link, $links)]);
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $actor = $this->actor($request);
        $link = $this->findVisible($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        if (! $actor->mayWrite()) {
            return new JsonResponse(['message' => 'This account cannot delete links.'], 403);
        }

        // Soft deleted: click events outlive the link, and a report with no link
        // metadata is a report nobody can read.
        $link->delete();

        return new JsonResponse(status: 204);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    /**
     * Answers null both for a link that does not exist and one the actor may not
     * see, so the caller cannot tell the difference.
     */
    private function findVisible(Request $request, string $publicId): ?Link
    {
        $link = Link::query()
            ->with(['domain', 'tags'])
            ->visibleTo($this->actor($request))
            ->where('public_id', $publicId)
            ->first();

        return $link instanceof Link ? $link : null;
    }

    private function resolveDomain(mixed $publicId): ?Domain
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $domain = Domain::query()->where('public_id', $publicId)->first();

        if (! $domain instanceof Domain) {
            throw ValidationException::withMessages(['domain' => 'That domain does not exist.']);
        }

        return $domain;
    }

    /**
     * @return list<string>
     */
    private function tagList(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, is_string(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Link $link, LinkService $links): array
    {
        $domain = $link->domain;

        return [
            'id' => $link->public_id,
            'slug' => $link->slug,
            'destination' => $link->destination,
            'domain' => $domain?->host,
            'short_url' => $domain === null ? null : 'https://'.$domain->host.'/'.$link->slug,
            'redirect_mode' => $link->redirect_mode?->value,
            'effective_redirect_mode' => $links->effectiveMode($link)->value,
            'password_protected' => $link->requiresPassword(),
            'expires_at' => $link->expires_at,
            'max_clicks' => $link->max_clicks,
            'click_count' => $link->click_count,
            'disabled' => $link->isDisabled(),
            'resolvable' => $link->resolvable(),
            'tags' => $link->tags->pluck('name')->all(),
            'created_at' => $link->created_at,
        ];
    }
}
