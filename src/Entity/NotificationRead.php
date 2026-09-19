<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationReadRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Marks one notification as read by one user.
 *
 * Notifications are installation-wide (fewohbee runs one database per
 * property), so "read" cannot live on the notification itself — two members of
 * staff read independently.
 */
#[ORM\Entity(repositoryClass: NotificationReadRepository::class)]
#[ORM\Table(name: 'notification_reads')]
#[ORM\UniqueConstraint(name: 'uniq_notification_read', columns: ['notification_id', 'user_id'])]
class NotificationRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Notification::class)]
    #[ORM\JoinColumn(name: 'notification_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Notification $notification;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'read_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $readAt;

    public function __construct()
    {
        $this->readAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function setNotification(Notification $notification): static
    {
        $this->notification = $notification;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }
}
