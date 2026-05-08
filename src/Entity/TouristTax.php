<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TouristTaxRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TouristTaxRepository::class)]
#[ORM\Table(name: 'tourist_taxes')]
class TouristTax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\ManyToMany(targetEntity: Subsidiary::class)]
    #[ORM\JoinTable(name: 'tourist_taxes_has_subsidiaries')]
    private Collection $subsidiaries;

    #[ORM\Column(name: 'valid_from', type: 'date', nullable: true)]
    private ?\DateTimeInterface $validFrom = null;

    #[ORM\Column(name: 'valid_to', type: 'date', nullable: true)]
    private ?\DateTimeInterface $validTo = null;

    #[ORM\ManyToOne(targetEntity: TaxRate::class)]
    #[ORM\JoinColumn(name: 'tax_rate_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?TaxRate $taxRate = null;

    #[ORM\ManyToOne(targetEntity: AccountingAccount::class)]
    #[ORM\JoinColumn(name: 'revenue_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?AccountingAccount $revenueAccount = null;

    #[ORM\Column(name: 'includes_vat', type: 'boolean')]
    private bool $includesVat = true;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'applies_only_to_adult', type: 'boolean')]
    private bool $appliesOnlyToAdult = false;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    /** @var Collection<int, TouristTaxRate> */
    #[ORM\OneToMany(mappedBy: 'touristTax', targetEntity: TouristTaxRate::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rates;

    public function __construct()
    {
        $this->subsidiaries = new ArrayCollection();
        $this->rates = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @return Collection<int, Subsidiary> */
    public function getSubsidiaries(): Collection
    {
        return $this->subsidiaries;
    }

    public function addSubsidiary(Subsidiary $s): self
    {
        if (!$this->subsidiaries->contains($s)) {
            $this->subsidiaries[] = $s;
        }

        return $this;
    }

    public function removeSubsidiary(Subsidiary $s): self
    {
        $this->subsidiaries->removeElement($s);

        return $this;
    }

    public function getValidFrom(): ?\DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeInterface $d): self
    {
        $this->validFrom = $d;

        return $this;
    }

    public function getValidTo(): ?\DateTimeInterface
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeInterface $d): self
    {
        $this->validTo = $d;

        return $this;
    }

    public function getTaxRate(): ?TaxRate
    {
        return $this->taxRate;
    }

    public function setTaxRate(?TaxRate $tr): self
    {
        $this->taxRate = $tr;

        return $this;
    }

    public function getRevenueAccount(): ?AccountingAccount
    {
        return $this->revenueAccount;
    }

    public function setRevenueAccount(?AccountingAccount $a): self
    {
        $this->revenueAccount = $a;

        return $this;
    }

    public function isIncludesVat(): bool
    {
        return $this->includesVat;
    }

    public function setIncludesVat(bool $v): self
    {
        $this->includesVat = $v;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $v): self
    {
        $this->active = $v;

        return $this;
    }

    public function isAppliesOnlyToAdult(): bool
    {
        return $this->appliesOnlyToAdult;
    }

    public function setAppliesOnlyToAdult(bool $v): self
    {
        $this->appliesOnlyToAdult = $v;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $v): self
    {
        $this->sortOrder = $v;

        return $this;
    }

    /** @return Collection<int, TouristTaxRate> */
    public function getRates(): Collection
    {
        return $this->rates;
    }

    public function addRate(TouristTaxRate $r): self
    {
        if (!$this->rates->contains($r)) {
            $this->rates[] = $r;
            $r->setTouristTax($this);
        }

        return $this;
    }

    public function removeRate(TouristTaxRate $r): self
    {
        if ($this->rates->removeElement($r)) {
            if ($r->getTouristTax() === $this) {
                $r->setTouristTax(null);
            }
        }

        return $this;
    }

    public function isValidOn(\DateTimeInterface $date): bool
    {
        if (!$this->active) {
            return false;
        }
        if (null !== $this->validFrom && $date < $this->validFrom) {
            return false;
        }
        if (null !== $this->validTo && $date > $this->validTo) {
            return false;
        }

        return true;
    }
}
