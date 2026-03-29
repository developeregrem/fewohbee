<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OnlineBookingMinStayOverrideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OnlineBookingMinStayOverrideRepository::class)]
#[ORM\Table(name: 'online_booking_min_stay_override')]
class OnlineBookingMinStayOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoomCategory::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?RoomCategory $roomCategory = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $endDate = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $minNights = null;

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

    public function getStartDate(): ?\DateTime
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTime $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTime
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTime $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getMinNights(): ?int
    {
        return $this->minNights;
    }

    public function setMinNights(?int $minNights): self
    {
        $this->minNights = $minNights;

        return $this;
    }
}
