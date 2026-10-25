<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\MonthlyStatsSnapshot;
use App\Entity\Subsidiary;
use App\Service\MonthlyStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MonthlyStatsManagerRecoveryTest extends KernelTestCase
{
    #[DataProvider('managerStates')]
    public function testSnapshotCreationRecoversTheManagerAndItsSubsidiary(bool $close, int $year): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(ManagerRegistry::class);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $subsidiary = $manager->getRepository(Subsidiary::class)->findOneBy([]);
        self::assertInstanceOf(Subsidiary::class, $subsidiary);
        $subsidiaryId = $subsidiary->getId();
        $subsidiaryCount = $manager->getRepository(Subsidiary::class)->count([]);
        $service = static::getContainer()->get(MonthlyStatsService::class);
        if ($close) {
            $manager->close();
        } else {
            $manager->clear();
        }

        $payload = $service->getOrCreateSnapshotWithWarnings(3, $year, $subsidiary);

        $recoveredManager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $recoveredManager);
        self::assertTrue($recoveredManager->isOpen());
        $snapshot = $payload['snapshot'];
        self::assertNotNull($snapshot->getId());
        $snapshotSubsidiary = $snapshot->getSubsidiary();
        self::assertInstanceOf(Subsidiary::class, $snapshotSubsidiary);
        self::assertSame($subsidiaryId, $snapshotSubsidiary->getId());
        self::assertTrue($recoveredManager->contains($snapshot));
        self::assertTrue($recoveredManager->contains($snapshotSubsidiary));
        self::assertSame($subsidiaryCount, $recoveredManager->getRepository(Subsidiary::class)->count([]));
        self::assertSame(['year' => $year, 'month' => 3], $snapshot->getMetrics()['period']);
        self::assertSame([], $payload['warnings']);

        $snapshotId = $snapshot->getId();
        $recoveredManager->clear();
        $stored = $recoveredManager->find(MonthlyStatsSnapshot::class, $snapshotId);
        self::assertInstanceOf(MonthlyStatsSnapshot::class, $stored);
        self::assertSame($subsidiaryId, $stored->getSubsidiary()?->getId());
        self::assertSame(['year' => $year, 'month' => 3], $stored->getMetrics()['period']);

        $cached = $service->getOrCreateSnapshotWithWarnings(3, $year, $subsidiary);
        self::assertSame($stored, $cached['snapshot']);
        $rebuilt = $service->getOrCreateSnapshotWithWarnings(3, $year, $subsidiary, true);
        self::assertSame($snapshotId, $rebuilt['snapshot']->getId());
        self::assertSame($subsidiaryCount, $recoveredManager->getRepository(Subsidiary::class)->count([]));
        self::assertSame(1, $recoveredManager->getRepository(MonthlyStatsSnapshot::class)->count([
            'month' => 3,
            'year' => $year,
            'subsidiary' => $snapshotSubsidiary,
        ]));
    }

    /** @return iterable<string, array{bool, int}> */
    public static function managerStates(): iterable
    {
        yield 'closed manager' => [true, 2718];
        yield 'cleared manager' => [false, 2719];
    }
}
