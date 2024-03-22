<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\PriceRepository')]
#[ORM\Table(name: 'prices')]
class Price
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'decimal', scale: 2)]
    private $price;
    #[ORM\Column(type: 'decimal', scale: 2)]
    private $vat;
    #[ORM\Column(type: 'string', length: 100)]
    private $description;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private $numberOfPersons;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private $minStay;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $active;
    #[ORM\Column(type: 'date', nullable: true)]
    private $seasonStart;
    #[ORM\Column(type: 'date', nullable: true)]
    private $seasonEnd;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $monday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $tuesday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $wednesday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $thursday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $friday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $saturday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $sunday;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private $allDays;
    #[ORM\Column(type: 'smallint')]
    private $type;
    #[ORM\ManyToMany(targetEntity: 'ReservationOrigin', inversedBy: 'prices')]
    #[ORM\JoinTable(name: 'prices_has_reservation_origins')]
    private $reservationOrigins;
    #[ORM\OneToMany(targetEntity: 'App\Entity\PricePeriod', mappedBy: 'price', orphanRemoval: true, cascade: ['persist'])]
    private $pricePeriods;
    #[ORM\Column(type: 'boolean')]
    private $allPeriods;
    #[ORM\ManyToOne(targetEntity: 'App\Entity\RoomCategory', inversedBy: 'prices')]
    #[ORM\JoinColumn(nullable: true)]
    private $roomCategory;
    #[ORM\Column(type: 'boolean')]
    private $includesVat;
    #[ORM\Column(type: 'boolean')]
    private $isFlatPrice;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->reservationOrigins = new ArrayCollection();
        $this->pricePeriods = new ArrayCollection();
        $this->allPeriods = true;
        $this->includesVat = true;
        $this->isFlatPrice = false;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getPrice()
    {
        return $this->price;
    }

    public function getVat()
    {
        return $this->vat;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getNumberOfPersons()
    {
        return $this->numberOfPersons;
    }

    public function getMinStay()
    {
        return $this->minStay;
    }

    public function getActive()
    {
        return $this->active;
    }

    public function getSeasonStart()
    {
        return $this->seasonStart;
    }

    public function getSeasonEnd()
    {
        return $this->seasonEnd;
    }

    public function getMonday()
    {
        return $this->monday;
    }

    public function getTuesday()
    {
        return $this->tuesday;
    }

    public function getWednesday()
    {
        return $this->wednesday;
    }

    public function getThursday()
    {
        return $this->thursday;
    }

    public function getFriday()
    {
        return $this->friday;
    }

    public function getSaturday()
    {
        return $this->saturday;
    }

    public function getSunday()
    {
        return $this->sunday;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setId($id): void
    {
        $this->id = $id;
    }

    public function setPrice($price): void
    {
        $this->price = $price;
    }

    public function setVat($vat): void
    {
        $this->vat = $vat;
    }

    public function setDescription($description): void
    {
        $this->description = $description;
    }

    public function setNumberOfPersons($numberOfPersons): void
    {
        $this->numberOfPersons = $numberOfPersons;
    }

    public function setMinStay($minStay): void
    {
        $this->minStay = $minStay;
    }

    public function setActive($active): void
    {
        $this->active = $active;
    }

    public function setSeasonStart($seasonStart): void
    {
        $this->seasonStart = $seasonStart;
    }

    public function setSeasonEnd($seasonEnd): void
    {
        $this->seasonEnd = $seasonEnd;
    }

    public function setMonday($monday): void
    {
        $this->monday = $monday;
    }

    public function setTuesday($tuesday): void
    {
        $this->tuesday = $tuesday;
    }

    public function setWednesday($wednesday): void
    {
        $this->wednesday = $wednesday;
    }

    public function setThursday($thursday): void
    {
        $this->thursday = $thursday;
    }

    public function setFriday($friday): void
    {
        $this->friday = $friday;
    }

    public function setSaturday($saturday): void
    {
        $this->saturday = $saturday;
    }

    public function setSunday($sunday): void
    {
        $this->sunday = $sunday;
    }

    public function setType($type): void
    {
        $this->type = $type;
    }

    public function getAllDays()
    {
        return $this->allDays;
    }

    public function setAllDays($allDays): void
    {
        $this->allDays = $allDays;
    }

    /**
     * Add reservationOrigins.
     *
     * @return Price
     */
    public function addReservationOrigin(ReservationOrigin $reservationOrigins)
    {
        if (!$this->reservationOrigins->contains($reservationOrigins)) {
            $this->reservationOrigins[] = $reservationOrigins;
        }

        return $this;
    }

    /**
     * Remove reservationOrigins.
     */
    public function removeReservationOrigin(ReservationOrigin $reservationOrigins)
    {
        if ($this->reservationOrigins->contains($reservationOrigins)) {
            $this->reservationOrigins->removeElement($reservationOrigins);
        }

        return $this;
    }

    /**
     * Get reservationOrigins.
     *
     * @return Collection
     */
    public function getReservationOrigins()
    {
        return $this->reservationOrigins;
    }

    /**
     * @return Collection|PricePeriod[]
     */
    public function getPricePeriods(): Collection
    {
        return $this->pricePeriods;
    }

    public function addPricePeriod(PricePeriod $pricePeriod): self
    {
        if (!$this->pricePeriods->contains($pricePeriod)) {
            $this->pricePeriods[] = $pricePeriod;
            $pricePeriod->setPrice($this);
        }

        return $this;
    }

    public function removePricePeriod(PricePeriod $pricePeriod): self
    {
        if ($this->pricePeriods->contains($pricePeriod)) {
            $this->pricePeriods->removeElement($pricePeriod);
            // set the owning side to null (unless already changed)
            if ($pricePeriod->getPrice() === $this) {
                $pricePeriod->setPrice(null);
            }
        }

        return $this;
    }

    public function getAllPeriods(): ?bool
    {
        return $this->allPeriods;
    }

    public function setAllPeriods(bool $allPeriods): self
    {
        $this->allPeriods = $allPeriods;

        return $this;
    }

    public function getRoomCategory(): ?RoomCategory
    {
        return $this->roomCategory;
    }

    public function setRoomCategory(?RoomCategory $roomCategory): self
    {
        $this->roomCategory = $roomCategory;

        return $this;
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

        return $this;
    }
}
