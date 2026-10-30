<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\RoomCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ApartmentCategoryTest extends TestCase
{
    public function testRoomRequiresACategory(): void
    {
        $room = new Appartment();
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $violations = $validator->validateProperty($room, 'roomCategory');

        self::assertCount(1, $violations);
        self::assertSame('appartment.category.required', $violations[0]->getMessageTemplate());

        $room->setRoomCategory(new RoomCategory());

        self::assertCount(0, $validator->validateProperty($room, 'roomCategory'));
    }
}
