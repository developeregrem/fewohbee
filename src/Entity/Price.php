<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\PriceRepository")
 * @ORM\Table(name="prices")
 **/

class Price
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $id;

    /** @ORM\Column(type="decimal", scale=2) * */
    private $price;

    /** @ORM\Column(type="decimal", scale=2) * */
    private $vat;

    /** @ORM\Column(type="string", length=100) * */
    private $description;

    /** @ORM\Column(type="smallint", nullable=true) * */
    private $numberOfPersons;

    /** @ORM\Column(type="smallint", nullable=true) * */
    private $minStay;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $active;

    /** @ORM\Column(type="date", nullable=true) * */
    private $seasonStart;

    /** @ORM\Column(type="date", nullable=true) * */
    private $seasonEnd;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $monday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $tuesday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $wednesday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $thursday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $friday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $saturday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $sunday;

    /** @ORM\Column(type="boolean", nullable=true) * */
    private $allDays;

    /** @ORM\Column(type="smallint") * */
    private $type;

    /**
     * @ORM\ManyToMany(targetEntity="ReservationOrigin", inversedBy="prices")
     * @ORM\JoinTable(name="prices_has_reservation_origins")
     */
    private $reservationOrigins;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\PricePeriod", mappedBy="price", orphanRemoval=true)
     */
    private $pricePeriods;

    /**
     * @ORM\Column(type="boolean")
     */
    private $allPeriods;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\RoomCategory", inversedBy="prices")
     * @ORM\JoinColumn(nullable=true)
     */
    private $roomCategory;

    /**
     * @ORM\Column(type="boolean")
     */
    private $includesVat;

    /**
     * @ORM\Column(type="boolean")
     */
    private $isFlatPrice;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->reservationOrigins = new \Doctrine\Common\Collections\ArrayCollection();
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

    public function setId($id)
    {
        $this->id = $id;
    }

    public function setPrice($price)
    {
        $this->price = $price;
    }

    public function setVat($vat)
    {
        $this->vat = $vat;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function setNumberOfPersons($numberOfPersons)
    {
        $this->numberOfPersons = $numberOfPersons;
    }

    public function setMinStay($minStay)
    {
        $this->minStay = $minStay;
    }

    public function setActive($active)
    {
        $this->active = $active;
    }

    public function setSeasonStart($seasonStart)
    {
        $this->seasonStart = $seasonStart;
    }

    public function setSeasonEnd($seasonEnd)
    {
        $this->seasonEnd = $seasonEnd;
    }

    public function setMonday($monday)
    {
        $this->monday = $monday;
    }

    public function setTuesday($tuesday)
    {
        $this->tuesday = $tuesday;
    }

    public function setWednesday($wednesday)
    {
        $this->wednesday = $wednesday;
    }

    public function setThursday($thursday)
    {
        $this->thursday = $thursday;
    }

    public function setFriday($friday)
    {
        $this->friday = $friday;
    }

    public function setSaturday($saturday)
    {
        $this->saturday = $saturday;
    }

    public function setSunday($sunday)
    {
        $this->sunday = $sunday;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function getAllDays()
    {
        return $this->allDays;
    }

    public function setAllDays($allDays)
    {
        $this->allDays = $allDays;
    }

    /**
     * Add reservationOrigins
     *
     * @param \App\Entity\ReservationOrigin $reservationOrigins
     * @return Price
     */
    public function addReservationOrigin(\App\Entity\ReservationOrigin $reservationOrigins)
    {
        $this->reservationOrigins[] = $reservationOrigins;

        return $this;
    }

    /**
     * Remove reservationOrigins
     *
     * @param \App\Entity\ReservationOrigin $reservationOrigins
     */
    public function removeReservationOrigin(\App\Entity\ReservationOrigin $reservationOrigins)
    {
        $this->reservationOrigins->removeElement($reservationOrigins);
    }

    /**
     * Get reservationOrigins
     *
     * @return \Doctrine\Common\Collections\Collection 
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
