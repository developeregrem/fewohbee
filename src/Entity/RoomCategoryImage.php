<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoomCategoryImageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents an uploaded image for a room category. Images are stored in three
 * size variants (thumbnail, medium, original) on disk. One image per category
 * can be marked as primary (hero image for the booking page).
 * Includes an OTA-ready tag field for future channel manager image categorization.
 */
#[ORM\Entity(repositoryClass: RoomCategoryImageRepository::class)]
#[ORM\Table(name: 'room_category_image')]
class RoomCategoryImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** The room category this image belongs to */
    #[ORM\ManyToOne(targetEntity: RoomCategory::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RoomCategory $roomCategory;

    /** Base filename (without variant prefix) stored on disk */
    #[ORM\Column(type: 'string', length: 255)]
    private string $filename;

    /** Display order for gallery/carousel (lower = first) */
    #[ORM\Column(type: 'smallint')]
    private int $sortOrder = 0;

    /** Whether this is the hero image shown in booking overview cards */
    #[ORM\Column(type: 'boolean')]
    private bool $isPrimary = false;

    /** OTA image category tag (room, bathroom, view, etc.) — nullable, for future use */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $tag = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoomCategory(): RoomCategory
    {
        return $this->roomCategory;
    }

    public function setRoomCategory(RoomCategory $roomCategory): self
    {
        $this->roomCategory = $roomCategory;

        return $this;
    }

    /** Returns the base filename (use RoomCategoryImageService for full paths with variant prefixes) */
    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

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

    /** Returns true if this is the hero/primary image for its room category */
    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;

        return $this;
    }

    /** Returns the OTA image tag (room, bathroom, view, etc.) for channel manager mapping */
    public function getTag(): ?string
    {
        return $this->tag;
    }

    public function setTag(?string $tag): self
    {
        $this->tag = $tag;

        return $this;
    }
}
