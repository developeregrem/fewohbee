<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\Api\SubsidiaryDto;
use App\Entity\Subsidiary;
use PHPUnit\Framework\TestCase;

final class SubsidiaryDtoTest extends TestCase
{
    public function testUnconfiguredOpeningHoursBecomeNull(): void
    {
        $dto = SubsidiaryDto::fromEntity($this->subsidiary());

        self::assertNull($dto->openingHours);
        self::assertStringContainsString('"openingHours":null', $this->encode($dto));
    }

    public function testRangesAreExposedWithNamedKeys(): void
    {
        $subsidiary = $this->subsidiary();
        $subsidiary->setOpeningHours([
            1 => [['08:00', '12:00'], ['16:00', '19:00']],
            6 => [['09:00', '12:00']],
        ]);

        $dto = SubsidiaryDto::fromEntity($subsidiary);

        self::assertSame([
            1 => [
                ['from' => '08:00', 'to' => '12:00'],
                ['from' => '16:00', 'to' => '19:00'],
            ],
            6 => [
                ['from' => '09:00', 'to' => '12:00'],
            ],
        ], $dto->openingHours);
    }

    /**
     * Weekday keys start at 1, so the map must never serialise as a JSON list — a consumer
     * indexing by weekday would otherwise silently read the wrong day.
     */
    public function testOpeningHoursSerialiseAsAnObject(): void
    {
        $subsidiary = $this->subsidiary();
        $subsidiary->setOpeningHours([1 => [['08:00', '12:00']]]);

        $json = $this->encode(SubsidiaryDto::fromEntity($subsidiary));

        self::assertStringContainsString('"openingHours":{"1":', $json);
    }

    public function testAllSevenWeekdaysStillSerialiseAsAnObject(): void
    {
        $subsidiary = $this->subsidiary();
        $subsidiary->setOpeningHours(array_fill_keys(range(1, 7), [['08:00', '12:00']]));

        $json = $this->encode(SubsidiaryDto::fromEntity($subsidiary));

        self::assertStringContainsString('"openingHours":{"1":', $json);
        self::assertStringContainsString('"7":', $json);
    }

    public function testTheInvoiceNumberPatternIsNotExposed(): void
    {
        $subsidiary = $this->subsidiary();
        $subsidiary->setInvoiceNumberPattern('RE-<year>-<number:4>');

        self::assertStringNotContainsString('RE-', $this->encode(SubsidiaryDto::fromEntity($subsidiary)));
    }

    private function subsidiary(): Subsidiary
    {
        $subsidiary = new Subsidiary();
        $subsidiary->setId(1);
        $subsidiary->setName('Main house');
        $subsidiary->setDescription('Reception building');

        return $subsidiary;
    }

    private function encode(SubsidiaryDto $dto): string
    {
        return json_encode($dto, \JSON_THROW_ON_ERROR);
    }
}
