<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Subsidiary;
use App\Service\OperationsFilterService;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class OperationsFilterServiceTest extends TestCase
{
    public function testArrayQueryPreservesRawValuesAndReplacesSessionFallback(): void
    {
        $values = ['chosen' => 'arrival', 5 => 2, 9 => null];
        $request = new Request(['categories' => $values]);
        $session = new Session(new MockArraySessionStorage());
        $session->set('frontdesk.categories', ['inhouse']);
        $service = new OperationsFilterService();

        $result = $service->resolveFilterArray($request, $session, 'frontdesk.categories', 'categories');

        self::assertSame($values, $result);
        self::assertSame($values, $session->get('frontdesk.categories'));
        self::assertSame(['arrival'], $service->normalizeCategories($result));
    }

    public function testScalarArrayQueryIsRejectedBeforeSessionChanges(): void
    {
        $request = new Request(['categories' => 'arrival']);
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method('set');

        $this->expectException(BadRequestException::class);

        new OperationsFilterService()->resolveFilterArray($request, $session, 'frontdesk.categories', 'categories');
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('storedArrayFilterValues')]
    public function testStoredArrayFilterPreservesArraysAndIgnoresInvalidValues(mixed $stored, array $expected): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('frontdesk.categories', $stored);

        $result = new OperationsFilterService()->resolveFilterArray(new Request(), $session, 'frontdesk.categories', 'categories');

        self::assertSame($expected, $result);
        self::assertSame($stored, $session->get('frontdesk.categories'));
    }

    /** @return iterable<string, array{mixed, array<array-key, mixed>}> */
    public static function storedArrayFilterValues(): iterable
    {
        yield 'stored array' => [['chosen' => 'arrival', 5 => 2], ['chosen' => 'arrival', 5 => 2]];
        yield 'empty array' => [[], []];
        yield 'legacy string' => ['arrival', []];
        yield 'null' => [null, []];
        yield 'boolean' => [false, []];
        yield 'integer' => [7, []];
    }

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
