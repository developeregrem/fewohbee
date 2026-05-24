<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Repository\GuestCategoryRepository;
use App\Service\GuestCategorySeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GuestCategorySeederTest extends TestCase
{
    public function testSeedDefaultsCreatesFourCategoriesOnFirstRun(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $repo = $this->createStub(GuestCategoryRepository::class);
        $repo->method('findBySystemCode')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $seeder = new GuestCategorySeeder($em, $repo, $translator);
        $seeder->seedDefaults();

        self::assertCount(4, $persisted);
        $codes = array_map(fn (GuestCategory $c) => $c->getSystemCode(), $persisted);
        self::assertSame(
            ['default_adult', 'default_child', 'default_infant', 'default_exempt'],
            $codes,
        );

        /** @var GuestCategory $adult */
        $adult = $persisted[0];
        self::assertTrue($adult->isAdult());
        self::assertTrue($adult->isCountedInOccupancy());
        self::assertSame(GuestStatisticalGroup::ADULT, $adult->getStatisticalGroup());

        /** @var GuestCategory $infant */
        $infant = $persisted[2];
        self::assertFalse($infant->isCountedInOccupancy());
        self::assertSame(GuestStatisticalGroup::INFANT, $infant->getStatisticalGroup());

        /** @var GuestCategory $exempt */
        $exempt = $persisted[3];
        self::assertSame(GuestStatisticalGroup::OTHER, $exempt->getStatisticalGroup());
    }

    public function testSeedDefaultsIsIdempotentAndUpdatesExisting(): void
    {
        $existingAdult = (new GuestCategory())
            ->setSystemCode('default_adult')
            ->setName('alt')
            ->setAcronym('alt')
            ->setStatisticalGroup(GuestStatisticalGroup::OTHER)
            ->setActive(false);

        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $repo = $this->createStub(GuestCategoryRepository::class);
        $repo->method('findBySystemCode')->willReturnCallback(
            fn (string $code) => 'default_adult' === $code ? $existingAdult : null,
        );

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $seeder = new GuestCategorySeeder($em, $repo, $translator);
        $seeder->seedDefaults();

        // Three new categories persisted, the existing adult one updated in place
        self::assertCount(3, $persisted);
        self::assertSame(GuestStatisticalGroup::ADULT, $existingAdult->getStatisticalGroup());
        self::assertTrue($existingAdult->isAdult());
        // Name/acronym are NOT touched on update — user edits preserved
        self::assertSame('alt', $existingAdult->getName());
        self::assertSame('alt', $existingAdult->getAcronym());
        // Active flag is NOT touched on update — user-facing toggle preserved
        self::assertFalse($existingAdult->isActive());
    }
}
