<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\DayPriceSource;
use App\Repository\DayPriceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The room price of one night for a room category in a subsidiary, stated for a number of guests
 * as the hotelier or a pricing tool names it. Other occupancies and origins follow in proportion
 * (see DayPriceResolver). A day price takes the place of the price rules on its night.
 */
#[ORM\Entity(repositoryClass: DayPriceRepository::class)]
#[ORM\Table(name: 'day_prices')]
#[ORM\UniqueConstraint(name: 'uniq_day_price_night', columns: ['subsidiary_id', 'room_category_id', 'night'])]
class DayPrice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    /** Doctrine hydrates the generated identifier, including on proxy subclasses. */
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Subsidiary::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Subsidiary $subsidiary;

    #[ORM\ManyToOne(targetEntity: RoomCategory::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RoomCategory $roomCategory;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $night;

    /** Room price of the night for $persons guests, as the price rows store it (gross or net). */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $persons = 1;

    #[ORM\Column(length: 10, enumType: DayPriceSource::class)]
    private DayPriceSource $source = DayPriceSource::MANUAL;

    /** The program that delivered the price, e.g. a pricing tool; shown to the hotelier. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $sourceLabel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Subsidiary $subsidiary, RoomCategory $roomCategory, \DateTimeImmutable $night)
    {
        $this->subsidiary = $subsidiary;
        $this->roomCategory = $roomCategory;
        $this->night = $night->setTime(0, 0);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubsidiary(): Subsidiary
    {
        return $this->subsidiary;
    }

    public function getRoomCategory(): RoomCategory
    {
        return $this->roomCategory;
    }

    public function getNight(): \DateTimeImmutable
    {
        return $this->night;
    }

    public function getAmount(): float
    {
        return (float) $this->amount;
    }

    public function getPersons(): int
    {
        return $this->persons;
    }

    public function getSource(): DayPriceSource
    {
        return $this->source;
    }

    public function getSourceLabel(): ?string
    {
        return $this->sourceLabel;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function set(float $amount, int $persons, DayPriceSource $source, ?string $sourceLabel, \DateTimeImmutable $now): void
    {
        if ($amount <= 0.0 || $amount >= 100000.0 || $persons < 1 || $persons > 100) {
            throw new \InvalidArgumentException('A day price needs a positive amount for at least one guest.');
        }
        $this->amount = number_format($amount, 2, '.', '');
        $this->persons = $persons;
        $this->source = $source;
        $this->sourceLabel = null === $sourceLabel ? null : mb_substr(trim($sourceLabel), 0, 60);
        $this->updatedAt = $now;
    }
}
