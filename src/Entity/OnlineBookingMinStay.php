<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineBookingMinStayRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OnlineBookingMinStayRepository::class)]
#[ORM\Table(name: 'online_booking_min_stay')]
#[ORM\UniqueConstraint(name: 'uniq_ob_min_stay_room_category', columns: ['room_category_id'])]
class OnlineBookingMinStay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoomCategory::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?RoomCategory $roomCategory = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $minNightsWeekday = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $minNightsWeekend = null;

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

    public function getMinNightsWeekday(): ?int
    {
        return $this->minNightsWeekday;
    }

    public function setMinNightsWeekday(?int $minNightsWeekday): self
    {
        $this->minNightsWeekday = $minNightsWeekday;

        return $this;
    }

    public function getMinNightsWeekend(): ?int
    {
        return $this->minNightsWeekend;
    }

    public function setMinNightsWeekend(?int $minNightsWeekend): self
    {
        $this->minNightsWeekend = $minNightsWeekend;

        return $this;
    }
}
