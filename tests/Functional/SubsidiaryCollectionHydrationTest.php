<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Subsidiary;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubsidiaryCollectionHydrationTest extends KernelTestCase
{
    public function testHydratedSubsidiaryExposesItsManagedApartmentCollection(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $subsidiary = $em->getRepository(Subsidiary::class)->findOneBy([]);
        self::assertInstanceOf(Subsidiary::class, $subsidiary);
        $id = $subsidiary->getId();
        $em->clear();

        $subsidiary = $em->find(Subsidiary::class, $id);
        self::assertInstanceOf(Subsidiary::class, $subsidiary);
        $apartments = $subsidiary->getAppartments();

        self::assertInstanceOf(PersistentCollection::class, $apartments);
        self::assertFalse($apartments->isInitialized());
        self::assertSame($apartments, $subsidiary->getAppartments());
        self::assertNotEmpty($apartments);
        foreach ($apartments as $apartment) {
            self::assertInstanceOf(Appartment::class, $apartment);
            self::assertSame($subsidiary, $apartment->getObject());
        }
        self::assertFalse($apartments->isDirty());
        $em->getUnitOfWork()->computeChangeSets();
        self::assertSame([], $em->getUnitOfWork()->getScheduledCollectionUpdates());
    }
}
