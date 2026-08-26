<?php

declare(strict_types=1);

namespace App\Auth\TwoFactor;

use App\Models\TwoFactorCredential;
use App\Models\User;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Illuminate\Support\Carbon;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys.
 *
 * The relying-party identifier is the instance's own host and is never taken
 * from the request: it is what binds a credential to this origin, and a
 * client-supplied one would let a credential be replayed from anywhere.
 *
 * Attestation is deliberately "none". Verifying it would mean carrying a
 * metadata service and deciding which authenticator vendors an operator is
 * allowed to own, which is a policy this product has no business holding.
 */
final class WebAuthnService
{
    private const TIMEOUT_MS = 60_000;

    public function __construct(
        private readonly string $relyingPartyId,
        private readonly string $relyingPartyName,
        private readonly string $origin,
    ) {}

    public function registrationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            rp: new PublicKeyCredentialRpEntity($this->relyingPartyName, $this->relyingPartyId),
            user: $this->userEntity($user),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', ES256::ID),
                PublicKeyCredentialParameters::create('public-key', RS256::ID),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            // Excluding what the account already holds stops one authenticator
            // being registered twice and silently shadowing itself.
            excludeCredentials: $this->descriptorsFor($user),
            timeout: self::TIMEOUT_MS,
        );
    }

    public function authenticationOptions(User $user): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->relyingPartyId,
            allowCredentials: $this->descriptorsFor($user),
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: self::TIMEOUT_MS,
        );
    }

    public function serialise(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer()->serialize($options, 'json');
    }

    public function deserialiseCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        return $this->serializer()->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    public function deserialiseRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        return $this->serializer()->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * Complete a registration and store the credential.
     */
    public function completeRegistration(
        User $user,
        PublicKeyCredentialCreationOptions $options,
        string $credentialJson,
        string $name,
    ): TwoFactorCredential {
        $credential = $this->loadCredential($credentialJson);
        $response = $credential->response;

        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new TwoFactorException('That is not a registration response.');
        }

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->creationCeremony())
                ->check($response, $options, $this->relyingPartyId);
        } catch (Throwable) {
            // The reason is never returned: a caller learning why a ceremony
            // failed learns about the instance, not about its own mistake.
            throw new TwoFactorException('That passkey could not be registered.');
        }

        $stored = new TwoFactorCredential;
        $stored->forceFill([
            'user_id' => $user->id,
            'type' => TwoFactorCredential::WEBAUTHN,
            'name' => $name,
            'credential_id' => $this->encode($record->publicKeyCredentialId),
            'public_key' => $this->serializer()->serialize($record, 'json'),
            'sign_count' => $record->counter,
            // A passkey proves itself during registration, so it is usable
            // immediately — unlike an authenticator secret, which is only
            // copied and could have been copied wrong.
            'confirmed_at' => Carbon::now(),
        ])->save();

        return $stored->refresh();
    }

    /**
     * Verify an assertion against the account's registered passkeys.
     */
    public function completeAuthentication(
        User $user,
        PublicKeyCredentialRequestOptions $options,
        string $credentialJson,
    ): bool {
        $credential = $this->loadCredential($credentialJson);
        $response = $credential->response;

        if (! $response instanceof AuthenticatorAssertionResponse) {
            return false;
        }

        $stored = TwoFactorCredential::query()
            ->where('user_id', $user->id)
            ->where('type', TwoFactorCredential::WEBAUTHN)
            ->whereNotNull('confirmed_at')
            ->where('credential_id', $this->encode($credential->rawId))
            ->first();

        if (! $stored instanceof TwoFactorCredential || ! is_string($stored->public_key)) {
            return false;
        }

        $record = $this->serializer()->deserialize($stored->public_key, CredentialRecord::class, 'json');

        try {
            $updated = AuthenticatorAssertionResponseValidator::create($this->requestCeremony())
                ->check(
                    credentialRecord: $record,
                    authenticatorAssertionResponse: $response,
                    publicKeyCredentialRequestOptions: $options,
                    host: $this->relyingPartyId,
                    userHandle: $this->userEntity($user)->id,
                );
        } catch (Throwable) {
            return false;
        }

        // The counter is what makes a cloned authenticator detectable, so the
        // stored value has to move forward with it.
        $stored->forceFill([
            'public_key' => $this->serializer()->serialize($updated, 'json'),
            'sign_count' => $updated->counter,
            'last_used_at' => Carbon::now(),
        ])->save();

        return true;
    }

    private function loadCredential(string $json): PublicKeyCredential
    {
        try {
            return $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (Throwable) {
            throw new TwoFactorException('That response could not be read.');
        }
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptorsFor(User $user): array
    {
        $descriptors = [];

        $credentials = TwoFactorCredential::query()
            ->where('user_id', $user->id)
            ->where('type', TwoFactorCredential::WEBAUTHN)
            ->whereNotNull('credential_id')
            ->get();

        foreach ($credentials as $credential) {
            $id = $credential->credential_id;

            if (is_string($id) && $id !== '') {
                $descriptors[] = PublicKeyCredentialDescriptor::create('public-key', $this->decode($id));
            }
        }

        return $descriptors;
    }

    private function userEntity(User $user): PublicKeyCredentialUserEntity
    {
        // The public identifier, never the integer key: a user handle is
        // returned to the client and stored by the authenticator.
        return PublicKeyCredentialUserEntity::create($user->email, $user->public_id, $user->name);
    }

    private function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationSupport()))->create();
    }

    private function attestationSupport(): AttestationStatementSupportManager
    {
        $manager = AttestationStatementSupportManager::create();
        $manager->add(NoneAttestationStatementSupport::create());

        return $manager;
    }

    private function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAttestationStatementSupportManager($this->attestationSupport());
        $factory->setAlgorithmManager(Manager::create()->add(ES256::create(), RS256::create()));
        $factory->setAllowedOrigins([$this->origin]);

        return $factory;
    }

    private function creationCeremony(): CeremonyStepManager
    {
        return $this->ceremonyFactory()->creationCeremony();
    }

    private function requestCeremony(): CeremonyStepManager
    {
        return $this->ceremonyFactory()->requestCeremony();
    }

    private function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function decode(string $encoded): string
    {
        $padded = str_pad(strtr($encoded, '-_', '+/'), (int) (ceil(strlen($encoded) / 4) * 4), '=');

        return base64_decode($padded, true) ?: '';
    }
}
