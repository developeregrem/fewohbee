<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TaxRate;
use App\Repository\TaxRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaxRateRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TaxRateRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $this->repo = $this->em->getRepository(TaxRate::class);
    }

    // ── findValidAt ─────────────────────────────────────────────────

    public function testFindValidAtReturnsUnboundedRates(): void
    {
        $rate = $this->createTaxRate('Unbounded', '10.00');
        $this->em->flush();

        $result = $this->repo->findValidAt(new \DateTime('2025-06-15'));

        self::assertContains($rate, $result);
    }

    public function testFindValidAtExcludesExpiredRate(): void
    {
        $rate = $this->createTaxRate('Expired', '11.00');
        $rate->setValidFrom(new \DateTime('2020-01-01'));
        $rate->setValidTo(new \DateTime('2020-12-31'));
        $this->em->flush();

        $result = $this->repo->findValidAt(new \DateTime('2025-06-15'));

        self::assertNotContains($rate, $result);
    }

    public function testFindValidAtExcludesFutureRate(): void
    {
        $rate = $this->createTaxRate('Future', '12.00');
        $rate->setValidFrom(new \DateTime('2030-01-01'));
        $this->em->flush();

        $result = $this->repo->findValidAt(new \DateTime('2025-06-15'));

        self::assertNotContains($rate, $result);
    }

    public function testFindValidAtIncludesRateOnBoundaryDates(): void
    {
        $rate = $this->createTaxRate('Bounded', '13.00');
        $rate->setValidFrom(new \DateTime('2025-01-01'));
        $rate->setValidTo(new \DateTime('2025-12-31'));
        $this->em->flush();

        self::assertContains($rate, $this->repo->findValidAt(new \DateTime('2025-01-01')));
        self::assertContains($rate, $this->repo->findValidAt(new \DateTime('2025-12-31')));
        self::assertNotContains($rate, $this->repo->findValidAt(new \DateTime('2024-12-31')));
        self::assertNotContains($rate, $this->repo->findValidAt(new \DateTime('2026-01-01')));
    }

    // ── findByRate with date ────────────────────────────────────────

    public function testFindByRateWithoutDateIgnoresValidity(): void
    {
        $this->createTaxRate('Expired 14', '14.00')
            ->setValidTo(new \DateTime('2020-12-31'));
        $this->em->flush();

        $found = $this->repo->findByRate(14.0);

        self::assertNotNull($found);
        self::assertSame('14.00', $found->getRate());
    }

    public function testFindByRateWithDateRespectsValidity(): void
    {
        $this->createTaxRate('Expired 15', '15.00')
            ->setValidTo(new \DateTime('2020-12-31'));
        $this->em->flush();

        $found = $this->repo->findByRate(15.0, new \DateTime('2025-06-15'));

        self::assertNull($found);
    }

    public function testFindByRateWithDateReturnsValidMatch(): void
    {
        $this->createTaxRate('Current 16', '16.00')
            ->setValidFrom(new \DateTime('2025-01-01'));
        $this->em->flush();

        $found = $this->repo->findByRate(16.0, new \DateTime('2025-06-15'));

        self::assertNotNull($found);
        self::assertSame('Current 16', $found->getName());
    }

    // ── createValidAtQueryBuilder ───────────────────────────────────

    public function testCreateValidAtQueryBuilderFiltersCorrectly(): void
    {
        $valid = $this->createTaxRate('QBValid', '17.00');
        $expired = $this->createTaxRate('QBExpired', '18.00');
        $expired->setValidTo(new \DateTime('2020-12-31'));
        $this->em->flush();

        $result = $this->repo->createValidAtQueryBuilder(new \DateTime('2025-06-15'))
            ->getQuery()
            ->getResult();

        self::assertContains($valid, $result);
        self::assertNotContains($expired, $result);
    }

    // ── Helper ──────────────────────────────────────────────────────

    private function createTaxRate(string $name, string $rate): TaxRate
    {
        $taxRate = new TaxRate();
        $taxRate->setName($name);
        $taxRate->setRate($rate);
        $this->em->persist($taxRate);

        return $taxRate;
    }
}
