<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Enums\WebhookEvent;
use App\Links\LinkException;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WebhookController
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse([
            'endpoints' => WebhookEndpoint::query()->orderBy('name')->get()
                ->map(fn (WebhookEndpoint $endpoint): array => $this->present($endpoint))->values()->all(),
            'events' => WebhookEvent::values(),
        ]);
    }

    public function store(Request $request, WebhookService $webhooks, AuditLog $audit): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        /** @var array{name: string, url: string, events: list<string>} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'url' => ['required', 'string', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'max:64'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $created = $webhooks->register($input['name'], $input['url'], $input['events'], $actor);
        } catch (LinkException $e) {
            throw ValidationException::withMessages(['url' => $e->getMessage()]);
        }

        $audit->record(
            AuditAction::WebhookRegistered,
            actor: $actor,
            targetType: 'webhook',
            targetId: $created['endpoint']->public_id,
            context: ['events' => implode(',', $created['endpoint']->events)],
            request: $request,
        );

        return new JsonResponse([
            'endpoint' => $this->present($created['endpoint']),
            // Shown once. It is not on the endpoint representation anywhere else.
            'secret' => $created['secret'],
        ], 201);
    }

    public function rotate(Request $request, WebhookEndpoint $endpoint, WebhookService $webhooks, AuditLog $audit): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $secret = $webhooks->rotate($endpoint);

        $audit->record(
            AuditAction::WebhookSecretRotated,
            actor: $request->user(),
            targetType: 'webhook',
            targetId: $endpoint->public_id,
            request: $request,
        );

        return new JsonResponse(['endpoint' => $this->present($endpoint->refresh()), 'secret' => $secret]);
    }

    public function update(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $request->validate(['disabled' => ['required', 'boolean']]);

        $endpoint->forceFill(['disabled_at' => $request->boolean('disabled') ? now() : null])->save();

        return new JsonResponse(['endpoint' => $this->present($endpoint->refresh())]);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint, AuditLog $audit): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $publicId = $endpoint->public_id;
        $endpoint->delete();

        $audit->record(
            AuditAction::WebhookRemoved,
            actor: $request->user(),
            targetType: 'webhook',
            targetId: $publicId,
            request: $request,
        );

        return new JsonResponse(status: 204);
    }

    public function deliveries(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse([
            'deliveries' => $endpoint->deliveries()->latest('id')->limit(50)->get()
                ->map(fn (WebhookDelivery $delivery): array => [
                    'id' => $delivery->public_id,
                    'event' => $delivery->event,
                    'status' => $delivery->status,
                    'attempts' => $delivery->attempts,
                    'status_code' => $delivery->last_status_code,
                    'error' => $delivery->last_error,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    public function replay(Request $request, WebhookDelivery $delivery, WebhookService $webhooks): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $replay = $webhooks->replay($delivery);

        return new JsonResponse(['delivery' => ['id' => $replay->public_id, 'status' => $replay->status]], 202);
    }

    private function administrates(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->administrates();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->public_id,
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'disabled' => $endpoint->isDisabled(),
        ];
    }
}
