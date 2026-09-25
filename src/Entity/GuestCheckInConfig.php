<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\GuestCheckInFieldMode;
use App\Repository\GuestCheckInConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Installation-wide settings of the online check-in (single row, like OnlineBookingConfig).
 *
 * Names and the arrival time are always asked; every other group of fields can be hidden,
 * offered or required, because registration duties differ from country to country.
 */
#[ORM\Entity(repositoryClass: GuestCheckInConfigRepository::class)]
#[ORM\Table(name: 'guest_check_in_config')]
#[ORM\HasLifecycleCallbacks]
class GuestCheckInConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $enabled = false;

    /** Street, zip, city and country of the main guest. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: GuestCheckInFieldMode::class, options: ['default' => 'required'])]
    private GuestCheckInFieldMode $addressMode = GuestCheckInFieldMode::REQUIRED;

    /** Birthday of the main guest and the fellow travellers. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: GuestCheckInFieldMode::class, options: ['default' => 'required'])]
    private GuestCheckInFieldMode $birthdayMode = GuestCheckInFieldMode::REQUIRED;

    /** Nationality of the main guest and the fellow travellers. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: GuestCheckInFieldMode::class, options: ['default' => 'required'])]
    private GuestCheckInFieldMode $nationalityMode = GuestCheckInFieldMode::REQUIRED;

    /** Type and number of the main guest's ID document. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: GuestCheckInFieldMode::class, options: ['default' => 'optional'])]
    private GuestCheckInFieldMode $idDocumentMode = GuestCheckInFieldMode::OPTIONAL;

    /** Email address and phone number of the main guest. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: GuestCheckInFieldMode::class, options: ['default' => 'optional'])]
    private GuestCheckInFieldMode $contactMode = GuestCheckInFieldMode::OPTIONAL;

    /** Names (and, per the modes above, birthday and nationality) of the fellow travellers. */
    #[ORM\Column(type: Types::STRING, length: 10, enumType: GuestCheckInFieldMode::class, options: ['default' => 'optional'])]
    private GuestCheckInFieldMode $companionsMode = GuestCheckInFieldMode::OPTIONAL;

    /** Shown above the form, e.g. a greeting or what the data is needed for. Plain text. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $introText = null;

    /** Link to the house's privacy policy (GDPR Art. 13 information). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Url(protocols: ['https', 'http'], requireTld: true)]
    #[Assert\Length(max: 255)]
    private ?string $privacyUrl = null;

    /** Short privacy notice shown with the form, as an alternative or addition to the link. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $privacyText = null;

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

    public function getAddressMode(): GuestCheckInFieldMode
    {
        return $this->addressMode;
    }

    public function setAddressMode(GuestCheckInFieldMode $addressMode): self
    {
        $this->addressMode = $addressMode;

        return $this;
    }

    public function getBirthdayMode(): GuestCheckInFieldMode
    {
        return $this->birthdayMode;
    }

    public function setBirthdayMode(GuestCheckInFieldMode $birthdayMode): self
    {
        $this->birthdayMode = $birthdayMode;

        return $this;
    }

    public function getNationalityMode(): GuestCheckInFieldMode
    {
        return $this->nationalityMode;
    }

    public function setNationalityMode(GuestCheckInFieldMode $nationalityMode): self
    {
        $this->nationalityMode = $nationalityMode;

        return $this;
    }

    public function getIdDocumentMode(): GuestCheckInFieldMode
    {
        return $this->idDocumentMode;
    }

    public function setIdDocumentMode(GuestCheckInFieldMode $idDocumentMode): self
    {
        $this->idDocumentMode = $idDocumentMode;

        return $this;
    }

    public function getContactMode(): GuestCheckInFieldMode
    {
        return $this->contactMode;
    }

    public function setContactMode(GuestCheckInFieldMode $contactMode): self
    {
        $this->contactMode = $contactMode;

        return $this;
    }

    public function getCompanionsMode(): GuestCheckInFieldMode
    {
        return $this->companionsMode;
    }

    public function setCompanionsMode(GuestCheckInFieldMode $companionsMode): self
    {
        $this->companionsMode = $companionsMode;

        return $this;
    }

    public function getIntroText(): ?string
    {
        return $this->introText;
    }

    public function setIntroText(?string $introText): self
    {
        $this->introText = self::normalizeNullableString($introText);

        return $this;
    }

    public function getPrivacyUrl(): ?string
    {
        return $this->privacyUrl;
    }

    public function setPrivacyUrl(?string $privacyUrl): self
    {
        $this->privacyUrl = self::normalizeNullableString($privacyUrl);

        return $this;
    }

    public function getPrivacyText(): ?string
    {
        return $this->privacyText;
    }

    public function setPrivacyText(?string $privacyText): self
    {
        $this->privacyText = self::normalizeNullableString($privacyText);

        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
