<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'invoice_positions')]
class InvoicePosition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private $id;
    #[ORM\Column(type: 'integer')]
    #[Assert\Positive]
    private $amount;
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private $description;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private $price;
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\PositiveOrZero]
    private float $vat;
    #[ORM\ManyToOne(targetEntity: 'Invoice', inversedBy: 'positions')]
    private $invoice;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $includesVat;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $isFlatPrice;
    #[ORM\Column(type: 'boolean')]
    private bool $isPerRoom;
    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccountingAccount $revenueAccount = null;

    /**
     * Visual / semantic grouping marker on the invoice. Values:
     *   - null         → legacy / unspecified
     *   - "apartment"  → overnight stay positions
     *   - "tourist_tax"→ tourist-tax block (separate sub-table)
     *   - "misc"       → ancillary services
     */
    #[ORM\Column(name: 'position_group', type: 'string', length: 32, nullable: true)]
    private ?string $positionGroup = null;

    /**
     * Whether this position was part of the booking a portal brokered.
     *
     * False for what the house sells the guest on top once they are there - a
     * breakfast added at the counter on a portal booking - which the portal
     * neither brokered nor processed, and which therefore carries none of its
     * fees. Inherited from the Price the position was made from, so the answer
     * is given once per service rather than per invoice. The invoice form asks
     * it again for a position added by hand, which has no price behind it, and
     * lets it be changed for the odd case.
     *
     * Recorded on the position rather than looked up later: what a portal
     * charged is a fact about this invoice, and a price whose flag is changed
     * next season must not rewrite it.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $brokered = true;

    /**
     * Whether a portal's commission is charged on this position.
     *
     * Everything brokered is commissionable, with one exception: a tourist tax
     * billed as its own position, which carries none. That holds as long as the
     * tax is set up as a separate item at the portal too; one buried in the room
     * rate is no separate tourist tax as far as the portal is concerned, and is
     * commissioned like the stay. The form says so where it is configured. No
     * setting covers that case for now - it can follow once a real one turns up.
     * The portal may still have collected the money, and then still charges its
     * payment fee on it, which is why this is a flag of its own rather than the
     * same one.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $commissionable = true;

    public function __construct()
    {
        $this->isFlatPrice = false;
        $this->includesVat = false;
        $this->isPerRoom = false;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function getVat(): float
    {
        return $this->vat;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getTotalPriceRaw(): float
    {
        return (float) $this->price * $this->getAmount();
    }

    public function getTotalPrice(): string
    {
        $price = $this->price * $this->getAmount();

        return number_format((float) $price, 2, ',', '.');
    }

    public function getPriceFormated(): string
    {
        return number_format((float) $this->price, 2, ',', '.');
    }

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function setDescription($description): void
    {
        $this->description = $description;
    }

    public function setPrice($price): void
    {
        $this->price = $price;
    }

    public function setVat($vat): void
    {
        $this->vat = (float) $vat;
    }

    public function setInvoice($invoice): void
    {
        $this->invoice = $invoice;
    }

    public function setAmount($amount): void
    {
        $this->amount = $amount;
    }

    public function getIncludesVat(): ?bool
    {
        return $this->includesVat;
    }

    public function setIncludesVat(bool $includesVat): self
    {
        $this->includesVat = $includesVat;

        return $this;
    }

    public function getIsFlatPrice(): ?bool
    {
        return $this->isFlatPrice;
    }

    public function setIsFlatPrice(bool $isFlatPrice): self
    {
        $this->isFlatPrice = $isFlatPrice;
        if ($isFlatPrice) {
            $this->isPerRoom = false;
        }

        return $this;
    }

    public function getNetPrice(): float
    {
        return $this->includesVat ? $this->price / (1 + $this->vat / 100) : (float) $this->price;
    }

    public function getIsPerRoom(): bool
    {
        return $this->isPerRoom;
    }

    public function setIsPerRoom(bool $isPerRoom): self
    {
        $this->isPerRoom = $isPerRoom;

        return $this;
    }

    public function getRevenueAccount(): ?AccountingAccount
    {
        return $this->revenueAccount;
    }

    public function setRevenueAccount(?AccountingAccount $revenueAccount): self
    {
        $this->revenueAccount = $revenueAccount;

        return $this;
    }

    public function getPositionGroup(): ?string
    {
        return $this->positionGroup;
    }

    public function setPositionGroup(?string $positionGroup): self
    {
        $this->positionGroup = $positionGroup;

        return $this;
    }

    /** Whether this position was part of the booking a portal brokered. */
    public function isBrokered(): bool
    {
        return $this->brokered;
    }

    public function setBrokered(bool $brokered): self
    {
        $this->brokered = $brokered;

        return $this;
    }

    /**
     * Answers both flags from the one question a user is asked about a position:
     * was it part of the portal booking.
     *
     * They only part company for a separately billed tourist tax, which is
     * taken to carry no commission however it was collected - so that position
     * keeps that answer whatever this one is.
     */
    public function markBrokered(bool $brokered): self
    {
        $this->brokered = $brokered;
        $this->commissionable = $brokered && 'tourist_tax' !== $this->positionGroup;

        return $this;
    }

    /** Whether a portal's commission is charged on this position. */
    public function isCommissionable(): bool
    {
        return $this->commissionable;
    }

    public function setCommissionable(bool $commissionable): self
    {
        $this->commissionable = $commissionable;

        return $this;
    }
}
