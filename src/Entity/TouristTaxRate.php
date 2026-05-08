<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'tourist_tax_rates')]
#[ORM\UniqueConstraint(name: 'UNIQ_ttr_tax_category', columns: ['tourist_tax_id', 'guest_category_id'])]
class TouristTaxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TouristTax::class, inversedBy: 'rates')]
    #[ORM\JoinColumn(name: 'tourist_tax_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TouristTax $touristTax = null;

    #[ORM\ManyToOne(targetEntity: GuestCategory::class)]
    #[ORM\JoinColumn(name: 'guest_category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?GuestCategory $guestCategory = null;

    #[ORM\Column(name: 'price_per_night', type: 'decimal', precision: 10, scale: 2)]
    private string $pricePerNight = '0.00';

    #[ORM\Column(name: 'report_group', type: 'string', length: 50, nullable: true)]
    private ?string $reportGroup = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTouristTax(): ?TouristTax
    {
        return $this->touristTax;
    }

    public function setTouristTax(?TouristTax $t): self
    {
        $this->touristTax = $t;

        return $this;
    }

    public function getGuestCategory(): ?GuestCategory
    {
        return $this->guestCategory;
    }

    public function setGuestCategory(?GuestCategory $c): self
    {
        $this->guestCategory = $c;

        return $this;
    }

    public function getPricePerNight(): string
    {
        return $this->pricePerNight;
    }

    public function getPricePerNightFloat(): float
    {
        return (float) $this->pricePerNight;
    }

    public function setPricePerNight(string|float|int $v): self
    {
        $this->pricePerNight = is_string($v) ? $v : number_format((float) $v, 2, '.', '');

        return $this;
    }

    public function getReportGroup(): ?string
    {
        return $this->reportGroup;
    }

    public function setReportGroup(?string $v): self
    {
        $this->reportGroup = $v;

        return $this;
    }
}
