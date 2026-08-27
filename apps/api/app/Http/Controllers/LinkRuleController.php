<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Links\LinkException;
use App\Links\LinkRuleService;
use App\Models\Link;
use App\Models\LinkRule;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A link's routing rules, read and written as an ordered set.
 *
 * There is no per-rule route on purpose: position is the semantics, and an
 * interface reordering by issuing one request per rule would pass through states
 * that violate the unique position constraint.
 */
final class LinkRuleController
{
    public function index(Request $request, string $publicId): JsonResponse
    {
        $link = $this->findVisible($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse(['rules' => $this->present($link)]);
    }

    public function replace(Request $request, string $publicId, LinkRuleService $rules, AuditLog $audit): JsonResponse
    {
        $link = $this->findVisible($request, $publicId);

        if ($link === null) {
            return new JsonResponse(status: 404);
        }

        $actor = $request->user();

        if (! $actor instanceof User || ! $actor->mayWrite()) {
            return new JsonResponse(['message' => 'This account cannot edit links.'], 403);
        }

        /** @var array{rules?: list<array<string, mixed>>} $input */
        $input = $request->validate([
            'rules' => ['present', 'array', 'max:'.LinkRuleService::MAX_PER_LINK],
            'rules.*.kind' => ['required', 'string', 'max:32'],
            'rules.*.value' => ['required', 'string', 'max:255'],
            'rules.*.destination' => ['required', 'string', 'max:2048'],
        ]);

        $prepared = [];

        foreach ($input['rules'] ?? [] as $rule) {
            $prepared[] = [
                'kind' => is_string($rule['kind'] ?? null) ? $rule['kind'] : '',
                'value' => is_string($rule['value'] ?? null) ? $rule['value'] : '',
                'destination' => is_string($rule['destination'] ?? null) ? $rule['destination'] : '',
            ];
        }

        try {
            $rules->replace($link, $prepared);
        } catch (LinkException $e) {
            throw ValidationException::withMessages(['rules' => $e->getMessage()]);
        }

        $audit->record(
            AuditAction::LinkRulesChanged,
            actor: $actor,
            targetType: 'link',
            targetId: $link->public_id,
            context: ['rules' => (string) count($prepared)],
            request: $request,
        );

        return new JsonResponse(['rules' => $this->present($link->refresh())]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(Link $link): array
    {
        /** @var list<array<string, mixed>> $rules */
        $rules = LinkRule::query()
            ->where('link_id', $link->id)
            ->orderBy('position')
            ->get()
            ->map(fn (LinkRule $rule): array => [
                'id' => $rule->public_id,
                'kind' => $rule->kind->value,
                'value' => $rule->value,
                'destination' => $rule->destination,
            ])
            ->values()
            ->all();

        return $rules;
    }

    /**
     * A link the acting account may see. Anything else answers as though it does
     * not exist — a 403 would confirm it does.
     */
    private function findVisible(Request $request, string $publicId): ?Link
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return null;
        }

        $link = Link::query()
            ->visibleTo($actor)
            ->where('public_id', $publicId)
            ->first();

        return $link instanceof Link ? $link : null;
    }
}
