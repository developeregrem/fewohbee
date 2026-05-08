<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ModifierType;
use App\Repository\GuestCategoryModifierRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: GuestCategoryModifierRepository::class)]
#[ORM\Table(name: 'guest_category_modifiers')]
class GuestCategoryModifier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GuestCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?GuestCategory $category = null;

    #[ORM\Column(type: 'string', enumType: ModifierType::class, length: 32)]
    private ModifierType $type = ModifierType::SURCHARGE_ABSOLUTE;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $value = '0.00';

    #[ORM\Column(name: 'valid_from', type: 'date', nullable: true)]
    private ?\DateTimeInterface $validFrom = null;

    #[ORM\Column(name: 'valid_to', type: 'date', nullable: true)]
    private ?\DateTimeInterface $validTo = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order', type: 'integer')]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?GuestCategory
    {
        return $this->category;
    }

    public function setCategory(?GuestCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getType(): ModifierType
    {
        return $this->type;
    }

    public function setType(ModifierType $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getValueAsFloat(): float
    {
        return (float) $this->value;
    }

    public function setValue(string|float|int $value): self
    {
        $this->value = is_string($value) ? $value : number_format((float) $value, 2, '.', '');

        return $this;
    }

    public function getValidFrom(): ?\DateTimeInterface
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeInterface $validFrom): self
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeInterface
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeInterface $validTo): self
    {
        $this->validTo = $validTo;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

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
