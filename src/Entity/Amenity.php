<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AmenityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a predefined amenity/feature (e.g. WiFi, parking, balcony) that can be
 * assigned to room categories. Amenities are seeded via migration and not user-created.
 * Includes OTA-ready mapping fields for future channel manager integration.
 */
#[ORM\Entity(repositoryClass: AmenityRepository::class)]
#[ORM\Table(name: 'amenity')]
class Amenity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Unique machine-readable identifier, e.g. "wifi", "parking", "balcony" */
    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $slug;

    /** Font Awesome class for icon display, e.g. "fa-solid fa-wifi" */
    #[ORM\Column(type: 'string', length: 100)]
    private string $iconFaClass;

    /** Grouping category: room, bathroom, kitchen, outdoor, other */
    #[ORM\Column(type: 'string', length: 30)]
    private string $category;

    /** Display order within the category group */
    #[ORM\Column(type: 'smallint')]
    private int $sortOrder = 0;

    /** Booking.com Room Amenity Code (OTA 2014B Standard) — nullable, for future use */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $bookingComRmaCode = null;

    /** Airbnb Amenity Identifier — nullable, for future use */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $airbnbAmenityId = null;

    /** Room categories that have this amenity assigned (inverse side) */
    #[ORM\ManyToMany(targetEntity: RoomCategory::class, mappedBy: 'amenities')]
    private Collection $roomCategories;

    public function __construct()
    {
        $this->roomCategories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getIconFaClass(): string
    {
        return $this->iconFaClass;
    }

    public function setIconFaClass(string $iconFaClass): self
    {
        $this->iconFaClass = $iconFaClass;

        return $this;
    }

    /** Returns the amenity group (room, bathroom, kitchen, outdoor, other) */
    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getBookingComRmaCode(): ?string
    {
        return $this->bookingComRmaCode;
    }

    public function setBookingComRmaCode(?string $bookingComRmaCode): self
    {
        $this->bookingComRmaCode = $bookingComRmaCode;

        return $this;
    }

    public function getAirbnbAmenityId(): ?string
    {
        return $this->airbnbAmenityId;
    }

    public function setAirbnbAmenityId(?string $airbnbAmenityId): self
    {
        $this->airbnbAmenityId = $airbnbAmenityId;

        return $this;
    }

    /** Returns all room categories that have this amenity assigned */
    public function getRoomCategories(): Collection
    {
        return $this->roomCategories;
    }

    /** Assigns this amenity to a room category (syncs both sides of the ManyToMany) */
    public function addRoomCategory(RoomCategory $roomCategory): self
    {
        if (!$this->roomCategories->contains($roomCategory)) {
            $this->roomCategories[] = $roomCategory;
            $roomCategory->addAmenity($this);
        }

        return $this;
    }

    /** Removes this amenity from a room category (syncs both sides of the ManyToMany) */
    public function removeRoomCategory(RoomCategory $roomCategory): self
    {
        if ($this->roomCategories->contains($roomCategory)) {
            $this->roomCategories->removeElement($roomCategory);
            $roomCategory->removeAmenity($this);
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->slug;
    }
}
