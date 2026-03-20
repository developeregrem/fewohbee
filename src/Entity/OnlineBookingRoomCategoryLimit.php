<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OnlineBookingRoomCategoryLimitRepository::class)]
#[ORM\Table(name: 'online_booking_room_category_limit')]
#[ORM\UniqueConstraint(name: 'uniq_ob_room_cat_limit', columns: ['room_category_id'])]
class OnlineBookingRoomCategoryLimit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoomCategory::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?RoomCategory $roomCategory = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $maxRooms = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getMaxRooms(): ?int
    {
        return $this->maxRooms;
    }

    public function setMaxRooms(?int $maxRooms): self
    {
        $this->maxRooms = $maxRooms;

        return $this;
    }
}
