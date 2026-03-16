<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineBookingConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OnlineBookingConfigRepository::class)]
#[ORM\Table(name: 'online_booking_config')]
#[ORM\HasLifecycleCallbacks]
class OnlineBookingConfig
{
    public const BOOKING_MODE_INQUIRY = 'INQUIRY';
    public const BOOKING_MODE_BOOKING = 'BOOKING';

    public const SUBSIDIARIES_MODE_ALL = 'ALL';
    public const SUBSIDIARIES_MODE_SELECTED = 'SELECTED';

    public const ROOMS_MODE_ALL = 'ALL';
    public const ROOMS_MODE_SELECTED = 'SELECTED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => self::BOOKING_MODE_INQUIRY])]
    private string $bookingMode = self::BOOKING_MODE_INQUIRY;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => self::SUBSIDIARIES_MODE_ALL])]
    private string $subsidiariesMode = self::SUBSIDIARIES_MODE_ALL;

    /** @var int[] */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $selectedSubsidiaryIds = [];

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => self::ROOMS_MODE_ALL])]
    private string $roomsMode = self::ROOMS_MODE_ALL;

    /** @var int[] */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $selectedRoomIds = [];

    #[ORM\Column(type: Types::STRING, length: 7, options: ['default' => '#1f6feb'])]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i')]
    private string $themePrimaryColor = '#1f6feb';

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    #[Assert\Regex('/^#[0-9a-f]{6}$/i')]
    private ?string $themeBackgroundColor = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $confirmationEmailTemplateId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $inquiryReservationStatusId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $bookingReservationStatusId = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $reservationOriginId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $paymentTerms = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $cancellationTerms = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $successMessageText = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $customCss = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime('now');
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTime('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getBookingMode(): string
    {
        return $this->bookingMode;
    }

    public function setBookingMode(string $bookingMode): self
    {
        $this->bookingMode = $bookingMode;

        return $this;
    }

    public function getSubsidiariesMode(): string
    {
        return $this->subsidiariesMode;
    }

    public function setSubsidiariesMode(string $subsidiariesMode): self
    {
        $this->subsidiariesMode = $subsidiariesMode;

        return $this;
    }

    /** @return int[] */
    public function getSelectedSubsidiaryIds(): array
    {
        return array_values(array_map('intval', $this->selectedSubsidiaryIds ?? []));
    }

    /** @param int[]|null $selectedSubsidiaryIds */
    public function setSelectedSubsidiaryIds(?array $selectedSubsidiaryIds): self
    {
        $this->selectedSubsidiaryIds = null === $selectedSubsidiaryIds
            ? []
            : array_values(array_unique(array_map('intval', $selectedSubsidiaryIds)));

        return $this;
    }

    public function getRoomsMode(): string
    {
        return $this->roomsMode;
    }

    public function setRoomsMode(string $roomsMode): self
    {
        $this->roomsMode = $roomsMode;

        return $this;
    }

    /** @return int[] */
    public function getSelectedRoomIds(): array
    {
        return array_values(array_map('intval', $this->selectedRoomIds ?? []));
    }

    /** @param int[]|null $selectedRoomIds */
    public function setSelectedRoomIds(?array $selectedRoomIds): self
    {
        $this->selectedRoomIds = null === $selectedRoomIds
            ? []
            : array_values(array_unique(array_map('intval', $selectedRoomIds)));

        return $this;
    }

    public function getThemePrimaryColor(): string
    {
        return $this->themePrimaryColor;
    }

    public function setThemePrimaryColor(string $themePrimaryColor): self
    {
        $this->themePrimaryColor = $themePrimaryColor;

        return $this;
    }

    public function getThemeBackgroundColor(): ?string
    {
        return $this->themeBackgroundColor;
    }

    public function setThemeBackgroundColor(?string $themeBackgroundColor): self
    {
        $this->themeBackgroundColor = $themeBackgroundColor ?: null;

        return $this;
    }

    public function getConfirmationEmailTemplateId(): ?int
    {
        return $this->confirmationEmailTemplateId;
    }

    public function setConfirmationEmailTemplateId(?int $confirmationEmailTemplateId): self
    {
        $this->confirmationEmailTemplateId = $confirmationEmailTemplateId;

        return $this;
    }

    public function getInquiryReservationStatusId(): ?int
    {
        return $this->inquiryReservationStatusId;
    }

    public function setInquiryReservationStatusId(?int $inquiryReservationStatusId): self
    {
        $this->inquiryReservationStatusId = $inquiryReservationStatusId;

        return $this;
    }

    public function getBookingReservationStatusId(): ?int
    {
        return $this->bookingReservationStatusId;
    }

    public function setBookingReservationStatusId(?int $bookingReservationStatusId): self
    {
        $this->bookingReservationStatusId = $bookingReservationStatusId;

        return $this;
    }

    public function getReservationOriginId(): ?int
    {
        return $this->reservationOriginId;
    }

    public function setReservationOriginId(?int $reservationOriginId): self
    {
        $this->reservationOriginId = $reservationOriginId;

        return $this;
    }

    public function getPaymentTerms(): ?string
    {
        return $this->paymentTerms;
    }

    public function setPaymentTerms(?string $paymentTerms): self
    {
        $this->paymentTerms = '' === trim((string) $paymentTerms) ? null : $paymentTerms;

        return $this;
    }

    public function getCancellationTerms(): ?string
    {
        return $this->cancellationTerms;
    }

    public function setCancellationTerms(?string $cancellationTerms): self
    {
        $this->cancellationTerms = '' === trim((string) $cancellationTerms) ? null : $cancellationTerms;

        return $this;
    }

    public function getSuccessMessageText(): ?string
    {
        return $this->successMessageText;
    }

    public function setSuccessMessageText(?string $successMessageText): self
    {
        $this->successMessageText = '' === trim((string) $successMessageText) ? null : $successMessageText;

        return $this;
    }

    public function getCustomCss(): ?string
    {
        return $this->customCss;
    }

    public function setCustomCss(?string $customCss): self
    {
        $this->customCss = '' === trim((string) $customCss) ? null : $customCss;

        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTime $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
