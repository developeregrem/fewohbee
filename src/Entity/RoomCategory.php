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

    public function __construct()
    {
        $this->apartments = new ArrayCollection();
        $this->prices = new ArrayCollection();
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
}
