<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: 'App\Repository\RoomCategoryRepository')]
class RoomCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private $name;
    #[ORM\OneToMany(targetEntity: 'App\Entity\Appartment', mappedBy: 'roomCategory')]
    private $apartments;
    #[ORM\OneToMany(targetEntity: 'App\Entity\Price', mappedBy: 'roomCategory')]
    private $prices;
    #[ORM\Column(type: 'string', length: 5, nullable: true)]
    #[Assert\Length(max: 5)]
    private $acronym;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    /** Standardized OTA room type code (e.g. "Double", "Twin", "Suite") — nullable, for future channel manager mapping */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $otaRoomTypeCode = null;

    /** Assigned amenities (e.g. WiFi, parking) — displayed as icons on the public booking page */
    #[ORM\ManyToMany(targetEntity: Amenity::class, inversedBy: 'roomCategories')]
    #[ORM\JoinTable(name: 'room_category_amenity')]
    #[ORM\OrderBy(['category' => 'ASC', 'sortOrder' => 'ASC'])]
    private Collection $amenities;

    /** Uploaded images for this category — shown as gallery/hero on the public booking page */
    #[ORM\OneToMany(targetEntity: RoomCategoryImage::class, mappedBy: 'roomCategory', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $images;

    public function __construct()
    {
        $this->apartments = new ArrayCollection();
        $this->prices = new ArrayCollection();
        $this->amenities = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection|Appartment[]
     */
    public function getApartments(): Collection
    {
        return $this->apartments;
    }

    public function addApartment(Appartment $apartment): self
    {
        if (!$this->apartments->contains($apartment)) {
            $this->apartments[] = $apartment;
            $apartment->setRoomCategory($this);
        }

        return $this;
    }

    public function removeApartment(Appartment $apartment): self
    {
        if ($this->apartments->contains($apartment)) {
            $this->apartments->removeElement($apartment);
            // set the owning side to null (unless already changed)
            if ($apartment->getRoomCategory() === $this) {
                $apartment->setRoomCategory(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection|Price[]
     */
    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function addPrice(Price $price): self
    {
        if (!$this->prices->contains($price)) {
            $this->prices[] = $price;
            $price->setRoomCategory($this);
        }

        return $this;
    }

    public function removePrice(Price $price): self
    {
        if ($this->prices->contains($price)) {
            $this->prices->removeElement($price);
            // set the owning side to null (unless already changed)
            if ($price->getRoomCategory() === $this) {
                $price->setRoomCategory(null);
            }
        }

        return $this;
    }

    public function getAcronym(): ?string
    {
        return $this->acronym;
    }

    public function setAcronym(?string $acronym): self
    {
        $this->acronym = $acronym;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): self
    {
        $this->details = '' === trim((string) $details) ? null : $details;

        return $this;
    }

    /** Returns the OTA room type code (e.g. "Double", "Twin", "Suite") for channel manager mapping */
    public function getOtaRoomTypeCode(): ?string
    {
        return $this->otaRoomTypeCode;
    }

    public function setOtaRoomTypeCode(?string $otaRoomTypeCode): self
    {
        $this->otaRoomTypeCode = $otaRoomTypeCode;

        return $this;
    }

    /** Returns all amenities assigned to this category, ordered by group and sortOrder */
    public function getAmenities(): Collection
    {
        return $this->amenities;
    }

    /** Assigns an amenity to this category (owning side of the ManyToMany) */
    public function addAmenity(Amenity $amenity): self
    {
        if (!$this->amenities->contains($amenity)) {
            $this->amenities[] = $amenity;
        }

        return $this;
    }

    /** Removes an amenity from this category */
    public function removeAmenity(Amenity $amenity): self
    {
        $this->amenities->removeElement($amenity);

        return $this;
    }

    /** Returns all images for this category, ordered by sortOrder */
    public function getImages(): Collection
    {
        return $this->images;
    }

    /** Adds an image and sets the owning side (roomCategory) */
    public function addImage(RoomCategoryImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images[] = $image;
            $image->setRoomCategory($this);
        }

        return $this;
    }

    /** Removes an image from this category (orphanRemoval deletes the DB record) */
    public function removeImage(RoomCategoryImage $image): self
    {
        $this->images->removeElement($image);

        return $this;
    }

    /** Returns the primary (hero) image, or the first image if none is marked as primary */
    public function getPrimaryImage(): ?RoomCategoryImage
    {
        foreach ($this->images as $image) {
            if ($image->isPrimary()) {
                return $image;
            }
        }

        return $this->images->first() ?: null;
    }
}
