<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Price;
use App\Entity\PricePeriod;
use App\GeoEntity\PostalCodeData;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReferenceDataHydrationTest extends KernelTestCase
{
    public function testPricePeriodKeepsItsDatesAndPriceWithoutSchedulingUpdates(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $price = $em->getRepository(Price::class)->findOneBy([]);
            self::assertInstanceOf(Price::class, $price);
            $priceId = $price->getId();
            $period = (new PricePeriod())
                ->setPrice($price)
                ->setStart(new \DateTime('2031-06-01'))
                ->setEnd(new \DateTime('2031-06-03'))
                ->setDescription('  Event  ');
            $em->persist($period);
            $em->flush();
            $id = $period->getId();
            self::assertNotNull($id);
            $em->clear();

            $period = $em->find(PricePeriod::class, $id);

            self::assertInstanceOf(PricePeriod::class, $period);
            self::assertSame('2031-06-01', $period->getStart()?->format('Y-m-d'));
            self::assertSame('2031-06-03', $period->getEnd()?->format('Y-m-d'));
            self::assertSame('Event', $period->getDescription());
            self::assertSame($priceId, $period->getPrice()?->getId());
            self::assertSame($em->find(Price::class, $priceId), $period->getPrice());
            $em->getUnitOfWork()->computeChangeSets();
            self::assertSame([], $em->getUnitOfWork()->getEntityChangeSet($period));
            self::assertSame([], $em->getUnitOfWork()->getScheduledEntityUpdates());
            self::assertSame([], $em->getUnitOfWork()->getScheduledCollectionUpdates());
            $em->flush();
        } finally {
            $em->clear();
            $connection->rollBack();
        }
    }

    #[DataProvider('postalCodes')]
    public function testPostalCodesKeepTheirStringRepresentationAndOptionalState(string $postalCode, ?string $state): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(ManagerRegistry::class);
        $em = $registry->getManagerForClass(PostalCodeData::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame($registry->getManager('geo'), $em);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $postal = (new PostalCodeData())
                ->setCountryCode('ZZ')
                ->setPostalCode($postalCode)
                ->setPlaceName('Synthetic place')
                ->setStateName($state)
                ->setStateNameShort(null);
            $em->persist($postal);
            $em->flush();
            $id = $postal->getId();
            self::assertNotNull($id);
            $em->clear();

            $postal = $em->find(PostalCodeData::class, $id);

            self::assertInstanceOf(PostalCodeData::class, $postal);
            self::assertSame('ZZ', $postal->getCountryCode());
            self::assertSame($postalCode, $postal->getPostalCode());
            self::assertSame('Synthetic place', $postal->getPlaceName());
            self::assertSame($state, $postal->getStateName());
            self::assertNull($postal->getStateNameShort());
            $repository = $em->getRepository(PostalCodeData::class);
            self::assertSame([$postal], $repository->findPlacesByCode('ZZ', substr($postalCode, 0, 3)));
            self::assertSame($state, $repository->findStateByCountryAndZip('ZZ', $postalCode));
            $em->getUnitOfWork()->computeChangeSets();
            self::assertSame([], $em->getUnitOfWork()->getEntityChangeSet($postal));
            self::assertSame([], $em->getUnitOfWork()->getScheduledEntityUpdates());
            $em->flush();
        } finally {
            $em->clear();
            $connection->rollBack();
        }
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function postalCodes(): iterable
    {
        yield 'leading zeros without state' => ['00123', null];
        yield 'alphanumeric postal code with state' => ['SW1A 1AA', 'Region'];
    }
}
