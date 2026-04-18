<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountingSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AccountingSettingsRepository::class)]
#[ORM\Table(name: 'accounting_settings')]
#[ORM\HasLifecycleCallbacks]
class AccountingSettings
{
    public const PRESET_SKR03 = 'skr03';
    public const PRESET_SKR04 = 'skr04';
    public const PRESET_EKR_AT = 'ekr_at';
    public const PRESET_KMU_CH = 'kmu_ch';

    public const VALID_PRESETS = [
        self::PRESET_SKR03,
        self::PRESET_SKR04,
        self::PRESET_EKR_AT,
        self::PRESET_KMU_CH,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Choice(choices: self::VALID_PRESETS)]
    private ?string $chartPreset = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $advisorNumber = null;

    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $clientNumber = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    #[Assert\Range(min: 1, max: 12)]
    private int $fiscalYearStart = 1;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 4])]
    #[Assert\Range(min: 4, max: 5)]
    private int $accountNumberLength = 4;

    #[ORM\Column(type: Types::STRING, length: 5, nullable: true, options: ['default' => 'WD'])]
    #[Assert\Length(max: 5)]
    private ?string $dictationCode = 'WD';

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

    public function getChartPreset(): ?string
    {
        return $this->chartPreset;
    }

    public function setChartPreset(?string $chartPreset): self
    {
        $this->chartPreset = $chartPreset;

        return $this;
    }

    public function getAdvisorNumber(): ?string
    {
        return $this->advisorNumber;
    }

    public function setAdvisorNumber(?string $advisorNumber): self
    {
        $this->advisorNumber = $advisorNumber;

        return $this;
    }

    public function getClientNumber(): ?string
    {
        return $this->clientNumber;
    }

    public function setClientNumber(?string $clientNumber): self
    {
        $this->clientNumber = $clientNumber;

        return $this;
    }

    public function getFiscalYearStart(): int
    {
        return $this->fiscalYearStart;
    }

    public function setFiscalYearStart(int $fiscalYearStart): self
    {
        $this->fiscalYearStart = $fiscalYearStart;

        return $this;
    }

    public function getAccountNumberLength(): int
    {
        return $this->accountNumberLength;
    }

    public function setAccountNumberLength(int $accountNumberLength): self
    {
        $this->accountNumberLength = $accountNumberLength;

        return $this;
    }

    public function getDictationCode(): ?string
    {
        return $this->dictationCode;
    }

    public function setDictationCode(?string $dictationCode): self
    {
        $this->dictationCode = $dictationCode;

        return $this;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }
}
