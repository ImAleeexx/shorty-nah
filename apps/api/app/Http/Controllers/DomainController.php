<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Domains\DomainException;
use App\Domains\DomainService;
use App\Domains\DomainVerifier;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DomainController
{
    public function index(Request $request, DomainService $domains): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        return new JsonResponse([
            'domains' => Domain::query()->orderByDesc('is_primary')->orderBy('host')->get()
                ->map(fn (Domain $domain): array => $this->present($domain, $domains)),
        ]);
    }

    public function store(Request $request, DomainService $domains, AuditLog $audit): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        /** @var array{host: string} $input */
        $input = $request->validate(['host' => ['required', 'string', 'max:253']]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $domain = $domains->register($input['host'], $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['host' => $e->getMessage()]);
        }

        $audit->record(
            AuditAction::DomainAdded,
            actor: $actor,
            targetType: 'domain',
            targetId: $domain->public_id,
            context: ['host' => $domain->host],
            request: $request,
        );

        return new JsonResponse([
            'domain' => $this->present($domain, $domains),
            // Shown so the operator can prove control; not a credential.
            'verification_token' => $domain->verification_token,
        ], 201);
    }

    public function verify(Request $request, Domain $domain, DomainVerifier $verifier, DomainService $domains): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $result = $verifier->verify($domain);

        return new JsonResponse([
            'domain' => $this->present($domain->refresh(), $domains),
            'verified' => $result->verified,
            'failure' => $result->failure,
        ], $result->verified ? 200 : 422);
    }

    public function promote(Request $request, Domain $domain, DomainService $domains): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        try {
            $domains->promoteToPrimary($domain);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        }

        return new JsonResponse(['domain' => $this->present($domain->refresh(), $domains)]);
    }

    public function destroy(Request $request, Domain $domain, DomainService $domains, AuditLog $audit): JsonResponse
    {
        if (! $this->administrates($request)) {
            return new JsonResponse(status: 404);
        }

        $confirmed = $request->boolean('confirm_link_deletion');

        $host = $domain->host;
        $publicId = $domain->public_id;

        try {
            $domains->delete($domain, $confirmed);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()])->status(422);
        }

        /** @var User $actor */
        $actor = $request->user();

        $audit->record(
            AuditAction::DomainRemoved,
            actor: $actor,
            targetType: 'domain',
            targetId: $publicId,
            context: ['host' => $host],
            request: $request,
        );

        return new JsonResponse(status: 204);
    }

    private function administrates(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->administrates();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Domain $domain, DomainService $domains): array
    {
        return [
            'id' => $domain->public_id,
            'host' => $domain->host,
            'primary' => $domain->is_primary,
            'verified' => $domain->isVerified(),
            'serves_links' => $domain->servesLinks(),
            'last_checked_at' => $domain->last_checked_at,
            'last_failure' => $domain->last_failure,
            'link_count' => $domains->linkCount($domain),
        ];
    }
}
