<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Ulid;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TrustPath\TrustPath;

#[Table(name: 'webauthn_credentials')]
#[Entity(repositoryClass: WebauthnCredentialRepository::class)]
class WebauthnCredential extends PublicKeyCredentialSource
{
    #[Id]
    #[Column(unique: true)]
    #[GeneratedValue(strategy: 'NONE')]
    private string $id;

    #[Column(name: 'client_label', type: 'string', length: 255, nullable: true)]
    private ?string $clientLabel = null;

    #[Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent = null;

    #[Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $publicKeyCredentialId,
        string $type,
        array $transports,
        string $attestationType,
        TrustPath $trustPath,
        AbstractUid $aaguid,
        string $credentialPublicKey,
        string $userHandle,
        int $counter
    ) {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        parent::__construct($publicKeyCredentialId, $type, $transports, $attestationType, $trustPath, $aaguid, $credentialPublicKey, $userHandle, $counter);
    }

    public function getId(): string
    {
        return $this->id;
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
