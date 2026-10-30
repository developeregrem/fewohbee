<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Price;
use App\Entity\RoomCategory;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A price serves either accommodation or event spaces, so that "night" or "day" in its labels
 * is unambiguous.
 */
final class PriceRoomKindTest extends TestCase
{
    public function testPriceForAccommodationOnly(): void
    {
        $price = $this->price(RoomCategoryKind::ACCOMMODATION, RoomCategoryKind::ACCOMMODATION);

        self::assertFalse($price->mixesRoomKinds());
        self::assertFalse($price->isForEventSpaces());
        self::assertSame([], $this->service()->validate($price));
    }

    public function testPriceForEventSpacesOnly(): void
    {
        $price = $this->price(RoomCategoryKind::EVENT_SPACE);

        self::assertFalse($price->mixesRoomKinds());
        self::assertTrue($price->isForEventSpaces());
        self::assertSame([], $this->service()->validate($price));
    }

    public function testMixedKindsAreRejected(): void
    {
        $price = $this->price(RoomCategoryKind::ACCOMMODATION, RoomCategoryKind::EVENT_SPACE);

        self::assertTrue($price->mixesRoomKinds());
        self::assertFalse($price->isForEventSpaces());
        self::assertSame(['price.flash.mixed_room_kinds'], $this->service()->validate($price));
    }

    public function testPriceWithoutCategoryIsNotPerDay(): void
    {
        // A misc price without categories applies to every room, so it keeps the night labels.
        $price = new Price();

        self::assertFalse($price->mixesRoomKinds());
        self::assertFalse($price->isForEventSpaces());
    }

    private function price(RoomCategoryKind ...$kinds): Price
    {
        $price = new Price();
        foreach ($kinds as $kind) {
            $price->addRoomCategory((new RoomCategory())->setName($kind->value)->setKind($kind));
        }

        return $price;
    }

    private function service(): PriceService
    {
        return new PriceService(
            $this->createStub(EntityManagerInterface::class),
            new ReservationPeriodService(),
        );
    }
}
