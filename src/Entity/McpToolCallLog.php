<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\McpToolCallOutcome;
use App\Repository\McpToolCallLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit record of one MCP tool call by an AI assistant. Holds no personal data from the call:
 * details carry ids, dates and counts only (see McpToolAuditor).
 */
#[ORM\Entity(repositoryClass: McpToolCallLogRepository::class)]
#[ORM\Table(name: 'mcp_tool_call_log')]
#[ORM\Index(name: 'idx_mcp_tool_call_log_created', columns: ['created_at'])]
class McpToolCallLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
    private ?string $username = null;

    #[ORM\ManyToOne(targetEntity: ApiToken::class)]
    #[ORM\JoinColumn(name: 'api_token_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ApiToken $apiToken = null;

    /** Kept when the token is deleted, so the entry stays attributable. */
    #[ORM\Column(name: 'token_prefix', type: Types::STRING, length: 12, nullable: true)]
    private ?string $tokenPrefix = null;

    #[ORM\Column(name: 'tool_name', type: Types::STRING, length: 100)]
    private string $toolName;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: McpToolCallOutcome::class)]
    private McpToolCallOutcome $outcome;

    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER)]
    private int $durationMs = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    /** User-Agent of the MCP client, truncated. */
    #[ORM\Column(name: 'client', type: Types::STRING, length: 100, nullable: true)]
    private ?string $client = null;

    #[ORM\Column(name: 'ip_address', type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    public function __construct(string $toolName, McpToolCallOutcome $outcome, \DateTimeImmutable $createdAt)
    {
        $this->toolName = $toolName;
        $this->outcome = $outcome;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getApiToken(): ?ApiToken
    {
        return $this->apiToken;
    }

    public function setApiToken(?ApiToken $apiToken): self
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getTokenPrefix(): ?string
    {
        return $this->tokenPrefix;
    }

    public function setTokenPrefix(?string $tokenPrefix): self
    {
        $this->tokenPrefix = $tokenPrefix;

        return $this;
    }

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getOutcome(): McpToolCallOutcome
    {
        return $this->outcome;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /** @param array<string, mixed>|null $details */
    public function setDetails(?array $details): self
    {
        $this->details = $details;

        return $this;
    }

    public function getClient(): ?string
    {
        return $this->client;
    }

    public function setClient(?string $client): self
    {
        $this->client = $client;

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
}
