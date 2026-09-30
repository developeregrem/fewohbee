<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BankImportDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An in-progress bank statement import of one user, stored as the serialized ImportState.
 *
 * Holds counterparty names, IBANs and purposes until the import is committed or discarded, and
 * at most RETENTION_DAYS after its last change (see BankImportDraftStore). Deliberately excluded
 * from the entity change log.
 */
#[ORM\Entity(repositoryClass: BankImportDraftRepository::class)]
#[ORM\Table(name: 'bank_import_drafts')]
#[ORM\Index(name: 'idx_bank_import_drafts_updated', columns: ['updated_at'])]
class BankImportDraft
{
    public const RETENTION_DAYS = 2;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $state;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(string $id, User $user, array $state)
    {
        $this->id = $id;
        $this->user = $user;
        $this->state = $state;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function setState(array $state): self
    {
        $this->state = $state;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** When the draft is deleted unless it changes again. */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->updatedAt->modify(\sprintf('+%d days', self::RETENTION_DAYS));
    }
}
