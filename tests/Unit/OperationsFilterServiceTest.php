<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Subsidiary;
use App\Service\OperationsFilterService;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationsFilterServiceTest extends TestCase
{
    #[DataProvider('unfilteredSelections')]
    public function testUnfilteredSelectionDoesNotQueryTheManager(string $id): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('getRepository');

        self::assertNull(new OperationsFilterService()->resolveSubsidiary($manager, $id));
    }

    /** @return iterable<string, array{string}> */
    public static function unfilteredSelections(): iterable
    {
        yield 'all subsidiaries' => ['all'];
        yield 'empty selection' => [''];
    }

    #[DataProvider('lookupResults')]
    public function testLookupUsesPersistenceContractAndKeepsTheIdentifier(?object $found, bool $isSubsidiary): void
    {
        $repository = $this->createMock(ObjectRepository::class);
        $repository->expects(self::once())->method('find')->with('007')->willReturn($found);
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('getRepository')->with(Subsidiary::class)->willReturn($repository);

        $result = new OperationsFilterService()->resolveSubsidiary($manager, '007');

        self::assertSame($isSubsidiary ? $found : null, $result);
    }

    /** @return iterable<string, array{object|null, bool}> */
    public static function lookupResults(): iterable
    {
        yield 'matching subsidiary' => [new Subsidiary(), true];
        yield 'missing subsidiary' => [null, false];
        yield 'unexpected entity' => [new \stdClass(), false];
    }
}
