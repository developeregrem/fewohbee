<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
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
 * Thin wrapper around web-auth/webauthn-lib that replaces the former
 * webauthn-symfony-bundle. Provides serializer, validators and option
 * generation without any Symfony bundle dependency.
 */
class WebauthnService
{
    private SerializerInterface $serializer;
    private AuthenticatorAttestationResponseValidator $attestationValidator;
    private AuthenticatorAssertionResponseValidator $assertionValidator;

    /**
     * @param string $rpId   Relying Party ID (domain, e.g. "example.com")
     * @param string $rpName Relying Party display name shown to the user during passkey ceremonies
     */
    public function __construct(
        private readonly string $rpId,
        private readonly string $rpName,
        private readonly WebauthnCredentialRepository $credentialRepository,
    ) {
        // "none" attestation is sufficient for most use cases (no hardware certificate verification)
        $attestationManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);

        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        // Ceremony step managers validate all WebAuthn security checks
        // (challenge, origin, signature, counter, …)
        $ceremonyFactory = new CeremonyStepManagerFactory();
        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create(
            $ceremonyFactory->creationCeremony()
        );
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create(
            $ceremonyFactory->requestCeremony()
        );
    }

    /**
     * Build the PublicKeyCredentialCreationOptions sent to the browser to
     * initiate a new passkey registration. Already-registered credentials
     * of the user are excluded so the authenticator won't create duplicates.
     */
    public function generateRegistrationOptions(PublicKeyCredentialUserEntity $userEntity): PublicKeyCredentialCreationOptions
    {
        $existingCredentials = $this->credentialRepository->findByUserHandle($userEntity->id);
        $excludeCredentials = array_map(
            fn (WebauthnCredential $c) => PublicKeyCredentialDescriptor::create(
                type: 'public-key',
                id: base64_decode($c->getPublicKeyCredentialId()),
                transports: $c->getTransports(),
            ),
            $existingCredentials
        );

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: $this->rpName, id: $this->rpId),
            user: $userEntity,
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(-7),   // ES256
                PublicKeyCredentialParameters::createPk(-257), // RS256
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60000,
        );
    }

    /**
     * Build the PublicKeyCredentialRequestOptions sent to the browser to
     * initiate a passkey login. If a userHandle is given the response
     * restricts allowed credentials to that user; otherwise the browser
     * may offer any discoverable credential (conditional UI / autofill).
     */
    public function generateAuthenticationOptions(?string $userHandle = null): PublicKeyCredentialRequestOptions
    {
        $allowCredentials = [];
        if (null !== $userHandle) {
            $credentials = $this->credentialRepository->findByUserHandle($userHandle);
            $allowCredentials = array_map(
                fn (WebauthnCredential $c) => PublicKeyCredentialDescriptor::create(
                    type: 'public-key',
                    id: base64_decode($c->getPublicKeyCredentialId()),
                    transports: $c->getTransports(),
                ),
                $credentials
            );
        }

        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->rpId,
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 60000,
        );
    }

    /**
     * Serialize creation- or request-options to JSON for the browser and session storage.
     */
    public function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer->serialize($options, 'json');
    }

    /**
     * Restore creation options from the JSON stored in the session (needed for verification).
     */
    public function deserializeCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        return $this->serializer->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    /**
     * Restore request options from the JSON stored in the session (needed for verification).
     */
    public function deserializeRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        return $this->serializer->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * Deserialize the PublicKeyCredential JSON sent by the browser after a
     * registration or authentication ceremony.
     */
    public function deserializeCredential(string $json): PublicKeyCredential
    {
        return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
    }

    /**
     * Verify a registration (attestation) response from the browser.
     * On success returns a CredentialRecord ready to be persisted.
     *
     * @throws \Throwable on any verification failure (invalid signature, challenge mismatch, …)
     */
    public function verifyRegistration(
        AuthenticatorAttestationResponse $response,
        PublicKeyCredentialCreationOptions $options,
        string $host,
    ): CredentialRecord {
        return $this->attestationValidator->check($response, $options, $host);
    }

    /**
     * Verify an authentication (assertion) response from the browser.
     * Returns an updated CredentialRecord with incremented counter and
     * refreshed backup flags — must be persisted afterwards.
     *
     * @throws \Throwable on any verification failure
     */
    public function verifyAuthentication(
        CredentialRecord $credentialRecord,
        AuthenticatorAssertionResponse $response,
        PublicKeyCredentialRequestOptions $options,
        string $host,
        ?string $userHandle = null,
    ): CredentialRecord {
        return $this->assertionValidator->check($credentialRecord, $response, $options, $host, $userHandle);
    }
}
