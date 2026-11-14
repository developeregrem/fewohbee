<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InvoiceCollectionHydrationTest extends KernelTestCase
{
    public function testReadingInvoiceAssociationsDoesNotScheduleDatabaseChanges(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $invoice = $em->getRepository(Invoice::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(Invoice::class, $invoice);
        $id = $invoice->getId();
        $em->clear();
        $invoice = $em->find(Invoice::class, $id);
        self::assertInstanceOf(Invoice::class, $invoice);

        $collections = [$invoice->getAppartments(), $invoice->getPositions(), $invoice->getReservations()];
        foreach ($collections as $collection) {
            self::assertInstanceOf(PersistentCollection::class, $collection);
            self::assertFalse($collection->isInitialized());
            self::assertFalse($collection->isDirty());
        }
        self::assertSame($collections, [$invoice->getAppartments(), $invoice->getPositions(), $invoice->getReservations()]);
        $uow = $em->getUnitOfWork();
        $uow->computeChangeSets();
        self::assertSame([], $uow->getEntityChangeSet($invoice));
        self::assertSame([], $uow->getScheduledEntityUpdates());
        self::assertSame([], $uow->getScheduledCollectionUpdates());

        foreach ($collections as $collection) {
            self::assertNotEmpty($collection, 'The seeded invoice contains all three association types.');
        }
        foreach ($invoice->getAppartments() as $apartment) {
            self::assertSame($invoice, $apartment->getInvoice());
        }
        foreach ($invoice->getPositions() as $position) {
            self::assertSame($invoice, $position->getInvoice());
        }
        $uow->computeChangeSets();
        self::assertSame([], $uow->getScheduledEntityUpdates());
        self::assertSame([], $uow->getScheduledCollectionUpdates());
        $em->flush();
    }
}
