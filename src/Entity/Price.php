<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Translation\ContentTranslatable;
use App\Entity\Translation\ContentTranslationsTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Repository\PriceRepository')]
#[ORM\Table(name: 'prices')]
class Price implements ContentTranslatable
{
    use ContentTranslationsTrait;

    /** @var int|'new'|null */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int|string|null $id = null;
    /** @var int|float|numeric-string|null Keep the assigned representation, including DBAL decimal strings. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private int|float|string|null $price = null;
    /** @var float|numeric-string|null Keep DBAL strings intact to avoid false updates after hydration. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private float|string|null $vat = null;
    #[ORM\Column(type: 'string', length: 100)]
    private ?string $description = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private int|string|null $numberOfPersons = null;
    #[ORM\Column(type: 'smallint', nullable: true)]
    private int|string|null $minStay = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $active = null;
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $seasonStart = null;
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $seasonEnd = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $monday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $tuesday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $wednesday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $thursday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $friday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $saturday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $sunday = null;
    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $allDays = null;
    #[ORM\Column(type: 'smallint')]
    private int|string|null $type = null;
    /** @var Collection<int, ReservationOrigin> */
    #[ORM\ManyToMany(targetEntity: ReservationOrigin::class, inversedBy: 'prices')]
    #[ORM\JoinTable(name: 'prices_has_reservation_origins')]
    private Collection $reservationOrigins;
    /** @var Collection<int, PricePeriod> */
    #[ORM\OneToMany(targetEntity: 'App\Entity\PricePeriod', mappedBy: 'price', orphanRemoval: true, cascade: ['persist'])]
    private Collection $pricePeriods;
    #[ORM\Column(type: 'boolean')]
    private bool $allPeriods;
    /**
     * Room categories this price is bound to. Apartment prices need at least one; for misc prices
     * an empty collection means "applies to every room category".
     *
     * @var Collection<int, RoomCategory>
     */
    #[ORM\ManyToMany(targetEntity: 'App\Entity\RoomCategory', inversedBy: 'prices')]
    #[ORM\JoinTable(name: 'prices_has_room_categories')]
    private Collection $roomCategories;
    #[ORM\Column(type: 'boolean')]
    private bool $includesVat;
    #[ORM\Column(type: 'boolean')]
    private bool $isFlatPrice;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isPerRoom;
    /**
     * Charge per night for every guest beyond numberOfPersons. Set, it widens a per-room price from
     * exactly numberOfPersons to every party up to the room's beds, the price including up to
     * numberOfPersons; null keeps the exact match.
     */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $extraPersonSurcharge = null;
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isDefaultActiveInReservationCreation;

    /**
     * Whether this service is part of what a portal brokers when it is billed on
     * a booking that came through one.
     *
     * False for what the house sells on site - a breakfast the guest orders at
     * the counter, a late checkout paid in cash - which a portal neither
     * brokered nor processed and charges no commission or payment fee on. True
     * for everything booked along with the stay, which is the ordinary case and
     * the default. Handed on to every invoice position made from this price,
     * which is where it is then recorded for good.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $brokered = true;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isBookableOnline;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isMandatoryOnline;
    /** @var Collection<int, PriceComponent> */
    #[ORM\OneToMany(targetEntity: 'App\Entity\PriceComponent', mappedBy: 'price', orphanRemoval: true, cascade: ['persist'])]
    #[ORM\OrderBy(['sortOrder' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    private Collection $components;
    #[ORM\ManyToOne(targetEntity: 'App\Entity\AccountingAccount')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AccountingAccount $revenueAccount = null;

    public function __construct()
    {
        $this->reservationOrigins = new ArrayCollection();
        $this->pricePeriods = new ArrayCollection();
        $this->components = new ArrayCollection();
        $this->roomCategories = new ArrayCollection();
        $this->allPeriods = true;
        $this->includesVat = true;
        $this->isFlatPrice = false;
        $this->isPerRoom = false;
        $this->isDefaultActiveInReservationCreation = true;
        $this->isBookableOnline = false;
        $this->isMandatoryOnline = false;
    }

    public static function translatableFields(): array
    {
        return ['description'];
    }

    /** @return int|'new'|null */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /** @return int|float|numeric-string|null */
    public function getPrice(): int|float|string|null
    {
        return $this->price;
    }

    public function getVat(): ?float
    {
        return null === $this->vat ? null : (float) $this->vat;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getNumberOfPersons(): int|string|null
    {
        return $this->numberOfPersons;
    }

    public function getMinStay(): int|string|null
    {
        return $this->minStay;
    }

    public function getActive(): ?bool
    {
        return $this->active;
    }

    public function getSeasonStart(): ?\DateTime
    {
        return $this->seasonStart;
    }

    public function getSeasonEnd(): ?\DateTime
    {
        return $this->seasonEnd;
    }

    public function getMonday(): ?bool
    {
        return $this->monday;
    }

    public function getTuesday(): ?bool
    {
        return $this->tuesday;
    }

    public function getWednesday(): ?bool
    {
        return $this->wednesday;
    }

    public function getThursday(): ?bool
    {
        return $this->thursday;
    }

    public function getFriday(): ?bool
    {
        return $this->friday;
    }

    public function getSaturday(): ?bool
    {
        return $this->saturday;
    }

    public function getSunday(): ?bool
    {
        return $this->sunday;
    }

    public function getType(): int|string|null
    {
        return $this->type;
    }

    /** @param int|'new' $id */
    public function setId(int|string $id): void
    {
        $this->id = $id;
    }

    /** @param int|float|numeric-string $price */
    public function setPrice(int|float|string $price): void
    {
        $this->price = $price;
    }

    public function setVat(float $vat): void
    {
        $this->vat = $vat;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function setNumberOfPersons(int|string|null $numberOfPersons): void
    {
        $this->numberOfPersons = $numberOfPersons;
    }

    public function setMinStay(int|string|null $minStay): void
    {
        $this->minStay = $minStay;
    }

    public function setActive(?bool $active): void
    {
        $this->active = $active;
    }

    public function setSeasonStart(?\DateTime $seasonStart): void
    {
        $this->seasonStart = $seasonStart;
    }

    public function setSeasonEnd(?\DateTime $seasonEnd): void
    {
        $this->seasonEnd = $seasonEnd;
    }

    public function setMonday(?bool $monday): void
    {
        $this->monday = $monday;
    }

    public function setTuesday(?bool $tuesday): void
    {
        $this->tuesday = $tuesday;
    }

    public function setWednesday(?bool $wednesday): void
    {
        $this->wednesday = $wednesday;
    }

    public function setThursday(?bool $thursday): void
    {
        $this->thursday = $thursday;
    }

    public function setFriday(?bool $friday): void
    {
        $this->friday = $friday;
    }

    public function setSaturday(?bool $saturday): void
    {
        $this->saturday = $saturday;
    }

    public function setSunday(?bool $sunday): void
    {
        $this->sunday = $sunday;
    }

    public function setType(int|string|null $type): void
    {
        $this->type = $type;
    }

    public function getAllDays(): ?bool
    {
        return $this->allDays;
    }

    public function setAllDays(?bool $allDays): void
    {
        $this->allDays = $allDays;
    }

    public function addReservationOrigin(ReservationOrigin $reservationOrigins): self
    {
        if (!$this->reservationOrigins->contains($reservationOrigins)) {
            $this->reservationOrigins[] = $reservationOrigins;
        }

        return $this;
    }

    public function removeReservationOrigin(ReservationOrigin $reservationOrigins): self
    {
        if ($this->reservationOrigins->contains($reservationOrigins)) {
            $this->reservationOrigins->removeElement($reservationOrigins);
        }

        return $this;
    }

    /** @return Collection<int, ReservationOrigin> */
    public function getReservationOrigins(): Collection
    {
        return $this->reservationOrigins;
    }

    /**
     * @return Collection<int, PricePeriod>
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

    /** Whether the row is valid on the weekday of this date. */
    public function coversWeekday(\DateTimeInterface $date): bool
    {
        if ($this->allDays) {
            return true;
        }

        return (bool) match ((int) $date->format('N')) {
            1 => $this->monday,
            2 => $this->tuesday,
            3 => $this->wednesday,
            4 => $this->thursday,
            5 => $this->friday,
            6 => $this->saturday,
            7 => $this->sunday,
        };
    }

    /**
     * Whether the row prices this night: all year or inside one of its periods (both days
     * inclusive), and on one of its weekdays. A list of rows in priority order is resolved by
     * taking the first row that covers the night.
     */
    public function coversNight(\DateTimeInterface $night): bool
    {
        if (!$this->coversWeekday($night)) {
            return false;
        }
        if ($this->allPeriods) {
            return true;
        }
        foreach ($this->pricePeriods as $period) {
            if ($night >= $period->getStart() && $night <= $period->getEnd()) {
                return true;
            }
        }

        return false;
    }

    public function setAllPeriods(bool $allPeriods): self
    {
        $this->allPeriods = $allPeriods;

        return $this;
    }

    /**
     * @return Collection<int, RoomCategory>
     */
    public function getRoomCategories(): Collection
    {
        return $this->roomCategories;
    }

    /**
     * A price serves either accommodation or event spaces, so that "night" or "day" in its
     * labels is unambiguous. Without categories a misc price applies to every room.
     */
    public function mixesRoomKinds(): bool
    {
        $kinds = [];
        foreach ($this->roomCategories as $category) {
            $kinds[$category->getKind()->value] = true;
        }

        return \count($kinds) > 1;
    }

    /** Charged per day: every assigned category is an event space. */
    public function isForEventSpaces(): bool
    {
        return !$this->roomCategories->isEmpty()
            && $this->roomCategories->forAll(static fn (int $key, RoomCategory $category): bool => $category->isEventSpace());
    }

    public function addRoomCategory(RoomCategory $roomCategory): self
    {
        if (!$this->roomCategories->contains($roomCategory)) {
            $this->roomCategories->add($roomCategory);
        }

        return $this;
    }

    public function removeRoomCategory(RoomCategory $roomCategory): self
    {
        $this->roomCategories->removeElement($roomCategory);

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
        if ($isFlatPrice) {
            $this->isPerRoom = false;
        }

        return $this;
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

    public function getExtraPersonSurcharge(): ?string
    {
        return $this->extraPersonSurcharge;
    }

    public function setExtraPersonSurcharge(int|float|string|null $surcharge): self
    {
        $this->extraPersonSurcharge = null === $surcharge || '' === $surcharge
            ? null
            : number_format((float) $surcharge, 2, '.', '');

        return $this;
    }

    /** Whether guests beyond numberOfPersons are billed through the surcharge (per-room prices only). */
    public function hasExtraPersonSurcharge(): bool
    {
        return $this->isPerRoom && null !== $this->extraPersonSurcharge;
    }

    /** Whether a portal brokers this service along with the stay, see the property. */
    public function isBrokered(): bool
    {
        return $this->brokered;
    }

    public function setBrokered(bool $brokered): self
    {
        $this->brokered = $brokered;

        return $this;
    }

    public function getIsDefaultActiveInReservationCreation(): bool
    {
        return $this->isDefaultActiveInReservationCreation;
    }

    public function setIsDefaultActiveInReservationCreation(bool $isDefaultActiveInReservationCreation): self
    {
        $this->isDefaultActiveInReservationCreation = $isDefaultActiveInReservationCreation;

        return $this;
    }

    public function getIsBookableOnline(): bool
    {
        return $this->isBookableOnline;
    }

    public function setIsBookableOnline(bool $isBookableOnline): self
    {
        $this->isBookableOnline = $isBookableOnline;

        return $this;
    }

    public function getIsMandatoryOnline(): bool
    {
        return $this->isMandatoryOnline;
    }

    public function setIsMandatoryOnline(bool $isMandatoryOnline): self
    {
        $this->isMandatoryOnline = $isMandatoryOnline;

        return $this;
    }

    /**
     * @return Collection<int, PriceComponent>
     */
    public function getComponents(): Collection
    {
        return $this->components;
    }

    public function addComponent(PriceComponent $component): self
    {
        if (!$this->components->contains($component)) {
            $this->components[] = $component;
            $component->setPrice($this);
        }

        return $this;
    }

    public function removeComponent(PriceComponent $component): self
    {
        if ($this->components->contains($component)) {
            $this->components->removeElement($component);
            if ($component->getPrice() === $this) {
                $component->setPrice(null);
            }
        }

        return $this;
    }

    public function isPackage(): bool
    {
        return !$this->components->isEmpty();
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
}
