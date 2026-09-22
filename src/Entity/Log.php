<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\LogAction;
use App\Repository\LogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LogRepository::class)]
#[ORM\Table(name: 'logging')]
#[ORM\Index(name: 'idx_logging_date', columns: ['date'])]
#[ORM\Index(name: 'idx_logging_entity', columns: ['entity_class', 'entity_id'])]
#[ORM\Index(name: 'idx_logging_user', columns: ['user_id'])]
class Log
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(name: 'entity_class', type: 'string', length: 255)]
    private string $entityClass;

    #[ORM\Column(name: 'entity_id', type: 'string', length: 64, nullable: true)]
    private ?string $entityId = null;

    #[ORM\Column(type: 'string', length: 16, enumType: LogAction::class)]
    private LogAction $action;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $changes = null;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Where the change came from: "web", "api" or "mcp" (AI assistant); null for console jobs. */
    #[ORM\Column(type: 'string', length: 8, nullable: true)]
    private ?string $channel = null;

    /** Display prefix of the API token that authenticated the change, if any. */
    #[ORM\Column(name: 'api_token_prefix', type: 'string', length: 12, nullable: true)]
    private ?string $apiTokenPrefix = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function setEntityClass(string $entityClass): self
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setEntityId(?string $entityId): self
    {
        $this->entityId = $entityId;

        return $this;
    }

    public function getAction(): LogAction
    {
        return $this->action;
    }

    public function setAction(LogAction $action): self
    {
        $this->action = $action;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getChanges(): ?array
    {
        return $this->changes;
    }

    /** @param array<string, mixed>|null $changes */
    public function setChanges(?array $changes): self
    {
        $this->changes = $changes;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(?string $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    public function getApiTokenPrefix(): ?string
    {
        return $this->apiTokenPrefix;
    }

    public function setApiTokenPrefix(?string $apiTokenPrefix): self
    {
        $this->apiTokenPrefix = $apiTokenPrefix;

        return $this;
    }
}
