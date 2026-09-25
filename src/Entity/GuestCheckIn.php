<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\GuestCheckInStatus;
use App\Repository\GuestCheckInRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Online check-in of one reservation: the link's selector and what the guest sent.
 *
 * The submission is kept apart from the guest records on purpose. The public form is reachable
 * with a link and a few booking details, so its input is reviewed by the hotelier before it
 * reaches Customer data (GuestCheckInApplyService); the payload is dropped once taken over or
 * discarded, and at the latest a few weeks after departure.
 *
 * The relation is owned here and not mapped on Reservation: an inverse one-to-one side cannot be
 * lazy and would cost a query for every reservation loaded anywhere in the application.
 */
#[ORM\Entity(repositoryClass: GuestCheckInRepository::class)]
#[ORM\Table(name: 'guest_check_in')]
class GuestCheckIn
{
    public const PAYLOAD_VERSION = 1;

    /**
     * Days after departure until the submitted data is deleted even if nobody reviewed it
     * (app:purge-logs). Taken-over data lives on in the guest records under their own rules.
     */
    public const RETENTION_DAYS_AFTER_DEPARTURE = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'reservation_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private Reservation $reservation;

    /**
     * Public half of the link token. Only the combination with its HMAC opens the check-in
     * (GuestCheckInTokenSigner), so this column alone does not give access. Replacing it
     * revokes every link sent so far. Binary collation: base64url is case-sensitive.
     */
    #[ORM\Column(type: Types::STRING, length: 22, unique: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $selector;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: GuestCheckInStatus::class, options: ['default' => 'open'])]
    private GuestCheckInStatus $status = GuestCheckInStatus::OPEN;

    /**
     * What the guest entered, versioned by the key "v" (PAYLOAD_VERSION). Contains personal data
     * including ID numbers; EntityChangeLogListener redacts it and it never leaves the admin UI.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstSubmittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSubmittedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Reservation $reservation, string $selector, ?\DateTimeImmutable $createdAt = null)
    {
        $this->reservation = $reservation;
        $this->selector = $selector;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function getSelector(): string
    {
        return $this->selector;
    }

    /** Revokes the current link; the caller hands out the one built from the new selector. */
    public function replaceSelector(string $selector): void
    {
        $this->selector = $selector;
    }

    public function getStatus(): GuestCheckInStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function hasPayload(): bool
    {
        return null !== $this->payload;
    }

    public function getFirstSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->firstSubmittedAt;
    }

    public function getLastSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->lastSubmittedAt;
    }

    public function getAppliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Stores a (possibly repeated) submission of the guest.
     *
     * @param array<string, mixed> $payload
     */
    public function recordSubmission(array $payload, \DateTimeImmutable $now): void
    {
        $this->payload = $payload;
        $this->status = GuestCheckInStatus::SUBMITTED;
        $this->firstSubmittedAt ??= $now;
        $this->lastSubmittedAt = $now;
    }

    /** The data now lives in the guest records; the submission copy is no longer needed. */
    public function markApplied(\DateTimeImmutable $now): void
    {
        $this->status = GuestCheckInStatus::APPLIED;
        $this->appliedAt = $now;
        $this->payload = null;
    }

    /** Throws the submission away and lets the guest start over with the same link. */
    public function discard(): void
    {
        $this->status = GuestCheckInStatus::OPEN;
        $this->payload = null;
    }
}
