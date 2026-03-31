<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Standalone entity for WebAuthn/Passkey credentials.
 *
 * Binary fields (publicKeyCredentialId, credentialPublicKey) are stored
 * as base64-encoded strings in the database. The conversion methods
 * fromCredentialRecord() / toCredentialRecord() handle encoding and
 * decoding transparently so the webauthn-lib validators receive raw
 * binary data as expected.
 */
#[ORM\Table(name: 'webauthn_credentials')]
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
class WebauthnCredential
{
    #[ORM\Id]
    #[ORM\Column(unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[ORM\Column(name: 'public_key_credential_id', type: 'text')]
    private string $publicKeyCredentialId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $type;

    #[ORM\Column(type: 'json')]
    private array $transports;

    #[ORM\Column(name: 'attestation_type', type: 'string', length: 255)]
    private string $attestationType;

    #[ORM\Column(name: 'trust_path', type: 'json')]
    private array $trustPath;

    #[ORM\Column(type: 'text')]
    private string $aaguid;

    #[ORM\Column(name: 'credential_public_key', type: 'text')]
    private string $credentialPublicKey;

    #[ORM\Column(name: 'user_handle', type: 'string', length: 255)]
    private string $userHandle;

    #[ORM\Column(type: 'integer')]
    private int $counter;

    #[ORM\Column(name: 'other_ui', type: 'json', nullable: true)]
    private ?array $otherUI = null;

    #[ORM\Column(name: 'backup_eligible', type: 'boolean', nullable: true)]
    private ?bool $backupEligible = null;

    #[ORM\Column(name: 'backup_status', type: 'boolean', nullable: true)]
    private ?bool $backupStatus = null;

    #[ORM\Column(name: 'uv_initialized', type: 'boolean', nullable: true)]
    private ?bool $uvInitialized = null;

    #[ORM\Column(name: 'client_label', type: 'string', length: 255, nullable: true)]
    private ?string $clientLabel = null;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Create a new entity from a CredentialRecord returned by the
     * webauthn-lib attestation validator after a successful registration.
     */
    public static function fromCredentialRecord(CredentialRecord $record): self
    {
        $entity = new self();
        $entity->publicKeyCredentialId = base64_encode($record->publicKeyCredentialId);
        $entity->type = $record->type;
        $entity->transports = $record->transports;
        $entity->attestationType = $record->attestationType;
        $entity->trustPath = [];
        $entity->aaguid = $record->aaguid->toRfc4122();
        $entity->credentialPublicKey = base64_encode($record->credentialPublicKey);
        $entity->userHandle = $record->userHandle;
        $entity->counter = $record->counter;
        $entity->otherUI = $record->otherUI;
        $entity->backupEligible = $record->backupEligible;
        $entity->backupStatus = $record->backupStatus;
        $entity->uvInitialized = $record->uvInitialized;

        return $entity;
    }

    /**
     * Convert to a webauthn-lib CredentialRecord for use with the
     * assertion validator during passkey authentication.
     */
    public function toCredentialRecord(): CredentialRecord
    {
        return new CredentialRecord(
            publicKeyCredentialId: base64_decode($this->publicKeyCredentialId),
            type: $this->type,
            transports: $this->transports,
            attestationType: $this->attestationType,
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString($this->aaguid),
            credentialPublicKey: base64_decode($this->credentialPublicKey),
            userHandle: $this->userHandle,
            counter: $this->counter,
            otherUI: $this->otherUI,
            backupEligible: $this->backupEligible,
            backupStatus: $this->backupStatus,
            uvInitialized: $this->uvInitialized,
        );
    }

    /**
     * Apply mutable fields (counter, backup flags) from the updated
     * CredentialRecord returned by the assertion validator after login.
     */
    public function updateFromCredentialRecord(CredentialRecord $record): void
    {
        $this->counter = $record->counter;
        $this->backupEligible = $record->backupEligible;
        $this->backupStatus = $record->backupStatus;
        $this->uvInitialized = $record->uvInitialized;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPublicKeyCredentialId(): string
    {
        return $this->publicKeyCredentialId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTransports(): array
    {
        return $this->transports;
    }

    public function getUserHandle(): string
    {
        return $this->userHandle;
    }

    public function getCredentialPublicKey(): string
    {
        return $this->credentialPublicKey;
    }

    public function getClientLabel(): ?string
    {
        return $this->clientLabel;
    }

    public function setClientLabel(?string $clientLabel): void
    {
        $this->clientLabel = $clientLabel;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): void
    {
        $this->userAgent = $userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }
}
