<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\NotificationSeverity;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted notification for the notification centre.
 *
 * Unlike the derived providers (open conflicts, pending reminders), these are
 * point-in-time events that do not resolve themselves — so they carry a per-user
 * read state via {@see NotificationRead}.
 *
 * The title is stored as a translation key plus parameters, never as finished
 * text: the same row then renders in German and English without a second column
 * and without a data migration when wording changes.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notifications_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_notifications_entity', columns: ['entity_class', 'entity_id'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Event that produced this, e.g. "online_booking.created". */
    #[ORM\Column(type: 'string', length: 64)]
    private string $type;

    #[ORM\Column(type: 'string', length: 16, enumType: NotificationSeverity::class)]
    private NotificationSeverity $severity = NotificationSeverity::INFO;

    #[ORM\Column(name: 'title_key', type: 'string', length: 191)]
    private string $titleKey;

    /** @var array<string, string|int>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $params = null;

    /** Route to open when the entry is clicked. Null means the entry is informational only. */
    #[ORM\Column(name: 'route_name', type: 'string', length: 191, nullable: true)]
    private ?string $routeName = null;

    /** @var array<string, string|int>|null */
    #[ORM\Column(name: 'route_params', type: 'json', nullable: true)]
    private ?array $routeParams = null;

    /** Only users holding this role see the entry. Null means every authenticated user. */
    #[ORM\Column(name: 'required_role', type: 'string', length: 64, nullable: true)]
    private ?string $requiredRole = null;

    #[ORM\Column(name: 'entity_class', type: 'string', length: 255, nullable: true)]
    private ?string $entityClass = null;

    #[ORM\Column(name: 'entity_id', type: 'string', length: 64, nullable: true)]
    private ?string $entityId = null;

    /**
     * Free-text explanation written by whoever configured the automation.
     *
     * With several automations feeding the bell, "Invoice RE-2026-0007" alone
     * does not say why it is being shown.
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getSeverity(): NotificationSeverity
    {
        return $this->severity;
    }

    public function setSeverity(NotificationSeverity $severity): static
    {
        $this->severity = $severity;

        return $this;
    }

    public function getTitleKey(): string
    {
        return $this->titleKey;
    }

    public function setTitleKey(string $titleKey): static
    {
        $this->titleKey = $titleKey;

        return $this;
    }

    /** @return array<string, string|int> */
    public function getParams(): array
    {
        return $this->params ?? [];
    }

    /** @param array<string, string|int>|null $params */
    public function setParams(?array $params): static
    {
        $this->params = $params;

        return $this;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function setRouteName(?string $routeName): static
    {
        $this->routeName = $routeName;

        return $this;
    }

    /** @return array<string, string|int> */
    public function getRouteParams(): array
    {
        return $this->routeParams ?? [];
    }

    /** @param array<string, string|int>|null $routeParams */
    public function setRouteParams(?array $routeParams): static
    {
        $this->routeParams = $routeParams;

        return $this;
    }

    public function getRequiredRole(): ?string
    {
        return $this->requiredRole;
    }

    public function setRequiredRole(?string $requiredRole): static
    {
        $this->requiredRole = $requiredRole;

        return $this;
    }

    public function getEntityClass(): ?string
    {
        return $this->entityClass;
    }

    public function setEntityClass(?string $entityClass): static
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    public function setEntityId(?string $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
