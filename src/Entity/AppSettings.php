<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AppSettingsRepository::class)]
#[ORM\Table(name: 'app_settings')]
#[ORM\HasLifecycleCallbacks]
class AppSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 3, options: ['default' => 'EUR'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(type: Types::STRING, length: 5, options: ['default' => '€'])]
    #[Assert\NotBlank]
    #[Assert\Length(max: 5)]
    private string $currencySymbol = '€';

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => 'Invoice-<number>'])]
    #[Assert\NotBlank]
    private string $invoiceFilenamePattern = 'Invoice-<number>';

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    private array $customerSalutations = ['Ms', 'Mr', 'Family'];

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $notificationEmail = null;

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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getCurrencySymbol(): string
    {
        return $this->currencySymbol;
    }

    public function setCurrencySymbol(string $currencySymbol): self
    {
        $this->currencySymbol = $currencySymbol;

        return $this;
    }

    public function getInvoiceFilenamePattern(): string
    {
        return $this->invoiceFilenamePattern;
    }

    public function setInvoiceFilenamePattern(string $invoiceFilenamePattern): self
    {
        $this->invoiceFilenamePattern = $invoiceFilenamePattern;

        return $this;
    }

    /** @return string[] */
    public function getCustomerSalutations(): array
    {
        return $this->customerSalutations;
    }

    /** @param string[] $customerSalutations */
    public function setCustomerSalutations(array $customerSalutations): self
    {
        $this->customerSalutations = array_values(array_map('trim', $customerSalutations));

        return $this;
    }

    public function getNotificationEmail(): ?string
    {
        return $this->notificationEmail;
    }

    public function setNotificationEmail(?string $notificationEmail): self
    {
        $this->notificationEmail = (null !== $notificationEmail && '' === trim($notificationEmail)) ? null : $notificationEmail;

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
