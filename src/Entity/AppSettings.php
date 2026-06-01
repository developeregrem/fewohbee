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

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $mailFromEmail = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $mailFromName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Email]
    private ?string $mailReturnPath = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $mailCopy = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 65535)]
    private ?int $smtpPort = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    #[Assert\Choice(choices: ['none', 'starttls', 'ssl'])]
    private ?string $smtpEncryption = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $smtpUsername = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $smtpPasswordEncrypted = null;

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

    public function getMailFromEmail(): ?string
    {
        return $this->mailFromEmail;
    }

    public function setMailFromEmail(?string $mailFromEmail): self
    {
        $this->mailFromEmail = $this->normalizeNullableString($mailFromEmail);

        return $this;
    }

    public function getMailFromName(): ?string
    {
        return $this->mailFromName;
    }

    public function setMailFromName(?string $mailFromName): self
    {
        $this->mailFromName = $this->normalizeNullableString($mailFromName);

        return $this;
    }

    public function getMailReturnPath(): ?string
    {
        return $this->mailReturnPath;
    }

    public function setMailReturnPath(?string $mailReturnPath): self
    {
        $this->mailReturnPath = $this->normalizeNullableString($mailReturnPath);

        return $this;
    }

    public function getMailCopy(): ?bool
    {
        return $this->mailCopy;
    }

    public function setMailCopy(?bool $mailCopy): self
    {
        $this->mailCopy = $mailCopy;

        return $this;
    }

    public function getSmtpHost(): ?string
    {
        return $this->smtpHost;
    }

    public function setSmtpHost(?string $smtpHost): self
    {
        $this->smtpHost = $this->normalizeNullableString($smtpHost);

        return $this;
    }

    public function getSmtpPort(): ?int
    {
        return $this->smtpPort;
    }

    public function setSmtpPort(?int $smtpPort): self
    {
        $this->smtpPort = $smtpPort;

        return $this;
    }

    public function getSmtpEncryption(): ?string
    {
        return $this->smtpEncryption;
    }

    public function setSmtpEncryption(?string $smtpEncryption): self
    {
        $this->smtpEncryption = $this->normalizeNullableString($smtpEncryption);

        return $this;
    }

    public function getSmtpUsername(): ?string
    {
        return $this->smtpUsername;
    }

    public function setSmtpUsername(?string $smtpUsername): self
    {
        $this->smtpUsername = $this->normalizeNullableString($smtpUsername);

        return $this;
    }

    public function getSmtpPasswordEncrypted(): ?string
    {
        return $this->smtpPasswordEncrypted;
    }

    public function setSmtpPasswordEncrypted(?string $smtpPasswordEncrypted): self
    {
        $this->smtpPasswordEncrypted = $this->normalizeNullableString($smtpPasswordEncrypted);

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

    private function normalizeNullableString(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
