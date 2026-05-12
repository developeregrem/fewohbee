<?php

declare(strict_types=1);

namespace App\Entity;

use App\Payment\Enum\PaymentIntent;
use App\Payment\Enum\PaymentKind;
use App\Payment\Enum\PaymentStatus;
use App\Repository\PaymentTransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentTransactionRepository::class)]
#[ORM\Table(name: 'payment_transactions')]
#[ORM\Index(name: 'idx_pt_provider_payment', columns: ['provider_id', 'provider_payment_id'])]
#[ORM\Index(name: 'idx_pt_external_reference', columns: ['external_reference'])]
#[ORM\Index(name: 'idx_pt_status', columns: ['status'])]
class PaymentTransaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $providerId;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $providerPaymentId;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $externalReference;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PaymentIntent::class)]
    private PaymentIntent $intent;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $purpose;

    /**
     * Caller-provided classification — see PaymentKind. The Payment core does
     * not interpret this field; it exists so application layers (booking,
     * accounting) can group multiple transactions per booking.
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true, enumType: PaymentKind::class)]
    private ?PaymentKind $kind = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $providerId,
        string $providerPaymentId,
        string $externalReference,
        float $amount,
        string $currency,
        string $purpose,
        PaymentIntent $intent,
        PaymentStatus $status = PaymentStatus::PENDING,
        ?PaymentKind $kind = null,
    ) {
        $this->providerId = $providerId;
        $this->providerPaymentId = $providerPaymentId;
        $this->externalReference = $externalReference;
        $this->amount = number_format($amount, 2, '.', '');
        $this->currency = $currency;
        $this->purpose = $purpose;
        $this->intent = $intent;
        $this->status = $status;
        $this->kind = $kind;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getProviderPaymentId(): string
    {
        return $this->providerPaymentId;
    }

    public function getExternalReference(): string
    {
        return $this->externalReference;
    }

    public function getAmount(): float
    {
        return (float) $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): self
    {
        if ($status !== $this->status) {
            $this->status = $status;
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getIntent(): PaymentIntent
    {
        return $this->intent;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getKind(): ?PaymentKind
    {
        return $this->kind;
    }

    /** @return array<string, mixed>|null */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed>|null $metadata */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
