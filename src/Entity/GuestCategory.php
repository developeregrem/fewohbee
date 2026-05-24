<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Repository\GuestCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuestCategoryRepository::class)]
#[ORM\Table(name: 'guest_categories')]
class GuestCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(type: 'string', length: 20)]
    #[Assert\NotBlank]
    private string $acronym = '';

    #[ORM\ManyToMany(targetEntity: Subsidiary::class)]
    #[ORM\JoinTable(name: 'guest_categories_has_subsidiaries')]
    private Collection $subsidiaries;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $minAge = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $maxAge = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isCountedInOccupancy = true;

    #[ORM\Column(type: 'string', enumType: GuestStatisticalGroup::class, length: 20)]
    private GuestStatisticalGroup $statisticalGroup = GuestStatisticalGroup::OTHER;

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'string', length: 50, unique: true, nullable: true)]
    private ?string $systemCode = null;

    public function __construct()
    {
        $this->subsidiaries = new ArrayCollection();
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

    public function getAcronym(): string
    {
        return $this->acronym;
    }

    public function setAcronym(string $acronym): self
    {
        $this->acronym = $acronym;

        return $this;
    }

    /** @return Collection<int, Subsidiary> */
    public function getSubsidiaries(): Collection
    {
        return $this->subsidiaries;
    }

    public function addSubsidiary(Subsidiary $subsidiary): self
    {
        if (!$this->subsidiaries->contains($subsidiary)) {
            $this->subsidiaries[] = $subsidiary;
        }

        return $this;
    }

    public function removeSubsidiary(Subsidiary $subsidiary): self
    {
        $this->subsidiaries->removeElement($subsidiary);

        return $this;
    }

    public function getMinAge(): ?int
    {
        return $this->minAge;
    }

    public function setMinAge(?int $minAge): self
    {
        $this->minAge = $minAge;

        return $this;
    }

    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    public function setMaxAge(?int $maxAge): self
    {
        $this->maxAge = $maxAge;

        return $this;
    }

    public function isAdult(): bool
    {
        return GuestStatisticalGroup::ADULT === $this->statisticalGroup;
    }

    public function isCountedInOccupancy(): bool
    {
        return $this->isCountedInOccupancy;
    }

    public function setIsCountedInOccupancy(bool $isCountedInOccupancy): self
    {
        $this->isCountedInOccupancy = $isCountedInOccupancy;

        return $this;
    }

    public function getStatisticalGroup(): GuestStatisticalGroup
    {
        return $this->statisticalGroup;
    }

    public function setStatisticalGroup(GuestStatisticalGroup $statisticalGroup): self
    {
        $this->statisticalGroup = $statisticalGroup;

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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getSystemCode(): ?string
    {
        return $this->systemCode;
    }

    public function setSystemCode(?string $systemCode): self
    {
        $this->systemCode = $systemCode;

        return $this;
    }

    public function isSystem(): bool
    {
        return null !== $this->systemCode && '' !== $this->systemCode;
    }

    public function getOtaCode(): ?string
    {
        return $this->statisticalGroup->otaCode();
    }
}
