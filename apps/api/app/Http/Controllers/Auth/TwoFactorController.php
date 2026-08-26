<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Auth\AuthenticationService;
use App\Auth\TwoFactor\PendingChallenge;
use App\Auth\TwoFactor\TwoFactorException;
use App\Auth\TwoFactor\TwoFactorService;
use App\Auth\TwoFactor\WebAuthnService;
use App\Models\TwoFactorCredential;
use App\Models\User;
use App\Settings\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Enrolment, and the challenge that stands between a password and a session.
 */
final class TwoFactorController
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    /**
     * What the signed-in account currently holds.
     */
    public function index(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse([
            'required' => $twoFactor->required(),
            'enrolled' => $twoFactor->enrolled($user),
            'recovery_codes_remaining' => $twoFactor->remainingRecoveryCodes($user),
            'credentials' => $twoFactor->confirmedCredentials($user)->map(
                static fn (TwoFactorCredential $credential): array => [
                    'id' => $credential->public_id,
                    'type' => $credential->type,
                    'name' => $credential->name,
                    // Listed with the date it was added, which is what makes an
                    // unrecognised factor noticeable.
                    'added_at' => $credential->created_at,
                    'last_used_at' => $credential->last_used_at,
                ],
            ),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    /**
     * Begin an authenticator enrolment. Inert until confirmed.
     */
    public function store(Request $request, TwoFactorService $twoFactor, SettingsStore $settings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array{name?: string} $input */
        $input = $request->validate(['name' => ['sometimes', 'string', 'max:100']]);

        $enrolment = $twoFactor->beginTotpEnrolment(
            $user,
            $input['name'] ?? 'Authenticator app',
            (string) ($settings->string('instance.name') ?? 'Shorty-Nah'),
        );

        return new JsonResponse([
            'id' => $enrolment['credential']->public_id,
            // Shown once, so the operator can enter it by hand where a camera is
            // not an option.
            'secret' => $enrolment['secret'],
            'uri' => $enrolment['uri'],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Confirm an enrolment with a generated code.
     */
    public function confirm(
        Request $request,
        string $publicId,
        TwoFactorService $twoFactor,
        AuditLog $audit,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $credential = TwoFactorCredential::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->first();

        // Someone else's enrolment is indistinguishable from one that never
        // existed.
        if (! $credential instanceof TwoFactorCredential) {
            return new JsonResponse(status: 404);
        }

        /** @var array{code: string} $input */
        $input = $request->validate(['code' => ['required', 'string', 'max:12']]);

        try {
            $recovery = $twoFactor->confirmTotp($credential, $input['code']);
        } catch (TwoFactorException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $audit->record(
            AuditAction::TwoFactorEnrolled,
            actor: $user,
            targetType: 'two_factor',
            targetId: $credential->public_id,
            context: ['type' => $credential->type],
            request: $request,
        );

        return new JsonResponse([
            'enrolled' => true,
            // Issued once, on the first factor only, and never shown again.
            'recovery_codes' => $recovery,
        ], headers: ['Cache-Control' => 'no-store']);
    }

    public function destroy(
        Request $request,
        string $publicId,
        TwoFactorService $twoFactor,
        AuditLog $audit,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $credential = TwoFactorCredential::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->first();

        if (! $credential instanceof TwoFactorCredential) {
            return new JsonResponse(status: 404);
        }

        try {
            $twoFactor->remove($credential);
        } catch (TwoFactorException $e) {
            throw ValidationException::withMessages(['credential' => $e->getMessage()])->status(422);
        }

        $audit->record(
            AuditAction::TwoFactorRemoved,
            actor: $user,
            targetType: 'two_factor',
            targetId: $publicId,
            request: $request,
        );

        return new JsonResponse(status: 204);
    }

    /**
     * Begin a passkey registration. The options are held in the session, because
     * the challenge they carry is what the ceremony is verified against.
     */
    public function passkeyOptions(Request $request, WebAuthnService $webauthn): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $options = $webauthn->registrationOptions($user);
        $serialised = $webauthn->serialise($options);

        $request->session()->put('webauthn.registration', $serialised);

        return new JsonResponse(
            json_decode($serialised, true),
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Complete a passkey registration.
     */
    public function passkeyStore(
        Request $request,
        WebAuthnService $webauthn,
        TwoFactorService $twoFactor,
        AuditLog $audit,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        /** @var array{credential: string, name?: string} $input */
        $input = $request->validate([
            'credential' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:100'],
        ]);

        $held = $request->session()->pull('webauthn.registration');

        if (! is_string($held)) {
            throw ValidationException::withMessages([
                'credential' => 'That registration has expired. Start again.',
            ])->status(410);
        }

        $first = ! $twoFactor->enrolled($user);

        try {
            $credential = $webauthn->completeRegistration(
                $user,
                $webauthn->deserialiseCreationOptions($held),
                $input['credential'],
                $input['name'] ?? 'Passkey',
            );
        } catch (TwoFactorException $e) {
            throw ValidationException::withMessages(['credential' => $e->getMessage()]);
        }

        $audit->record(
            AuditAction::TwoFactorEnrolled,
            actor: $user,
            targetType: 'two_factor',
            targetId: $credential->public_id,
            context: ['type' => $credential->type],
            request: $request,
        );

        return new JsonResponse([
            'id' => $credential->public_id,
            'name' => $credential->name,
            'added_at' => $credential->created_at,
            'recovery_codes' => $first ? $twoFactor->issueRecoveryCodes($user) : null,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Options for satisfying a pending challenge with a passkey.
     */
    public function passkeyChallengeOptions(
        Request $request,
        PendingChallenge $challenge,
        WebAuthnService $webauthn,
    ): JsonResponse {
        $user = $challenge->user($request);

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => 'That sign-in has expired. Start again.',
            ])->status(410);
        }

        $options = $webauthn->authenticationOptions($user);
        $serialised = $webauthn->serialise($options);

        $request->session()->put('webauthn.authentication', $serialised);

        return new JsonResponse(
            json_decode($serialised, true),
            headers: ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Satisfy a pending challenge with an authenticator code or a recovery code.
     */
    public function challenge(
        Request $request,
        TwoFactorService $twoFactor,
        PendingChallenge $challenge,
        AuthenticationService $auth,
        AuditLog $audit,
    ): JsonResponse {
        $user = $challenge->user($request);

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'code' => 'That sign-in has expired. Start again.',
            ])->status(410);
        }

        /** @var array{code?: string, recovery_code?: string, credential?: string} $input */
        $input = $request->validate([
            'code' => ['nullable', 'string', 'max:12'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
            'credential' => ['nullable', 'string'],
        ]);

        $key = 'two-factor:'.sha1((string) $user->id);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => sprintf('Too many attempts. Try again in %d seconds.', RateLimiter::availableIn($key)),
            ])->status(429);
        }

        $remaining = null;

        if (($input['credential'] ?? null) !== null && $input['credential'] !== '') {
            $held = $request->session()->pull('webauthn.authentication');

            $satisfied = is_string($held) && app(WebAuthnService::class)->completeAuthentication(
                $user,
                app(WebAuthnService::class)->deserialiseRequestOptions($held),
                (string) $input['credential'],
            );
        } elseif (($input['recovery_code'] ?? null) !== null && $input['recovery_code'] !== '') {
            $remaining = $twoFactor->consumeRecoveryCode($user, $input['recovery_code']);
            $satisfied = $remaining !== null;
        } else {
            $satisfied = $twoFactor->verifyTotp($user, (string) ($input['code'] ?? ''));
        }

        if (! $satisfied) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            $audit->record(
                AuditAction::SignInFailed,
                actor: $user,
                context: ['stage' => 'two_factor'],
                request: $request,
            );

            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }

        RateLimiter::clear($key);
        $challenge->clear($request);

        $auth->establishSession($request, $user);

        $audit->record(AuditAction::SignInSucceeded, actor: $user, request: $request);

        return new JsonResponse([
            'user' => [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
            ],
            // Telling the account how many are left is the point of a
            // single-use code: silently running out is how people get locked out.
            'recovery_codes_remaining' => $remaining ?? $twoFactor->remainingRecoveryCodes($user),
        ], headers: ['Cache-Control' => 'no-store']);
    }
}
