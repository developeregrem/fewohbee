<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileCorrespondence;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\Template;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReservationCorrespondenceHydrationTest extends KernelTestCase
{
    public function testHydrationKeepsAssociationsAndBinaryContentWithoutSchedulingUpdates(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();

        try {
            $reservation = $em->getRepository(Reservation::class)->findOneBy([], ['id' => 'ASC']);
            self::assertInstanceOf(Reservation::class, $reservation);
            $template = $em->getRepository(Template::class)->findOneBy([]);
            self::assertInstanceOf(Template::class, $template);
            $bytes = "%PDF\0binary\xff\r\n";
            $file = (new FileCorrespondence())
                ->setName('Hydrated file')
                ->setFileName('document.pdf')
                ->setText('')
                ->setTemplate($template)
                ->setReservation($reservation)
                ->setBinaryPayload($bytes);
            $mail = (new MailCorrespondence())
                ->setName('Hydrated mail')
                ->setRecipient('guest@example.com')
                ->setSubject('Subject')
                ->setText('<p>Message</p>')
                ->setTemplate($template)
                ->setReservation($reservation)
                ->addChild($file);
            $file->addParent($mail);
            $em->persist($file);
            $em->persist($mail);
            $em->flush();
            $reservationId = $reservation->getId();
            $templateId = $template->getId();
            $fileId = $file->getId();
            $mailId = $mail->getId();
            $em->clear();

            $reservation = $em->find(Reservation::class, $reservationId);
            $file = $em->find(FileCorrespondence::class, $fileId);
            $mail = $em->find(MailCorrespondence::class, $mailId);

            self::assertInstanceOf(Reservation::class, $reservation);
            self::assertInstanceOf(FileCorrespondence::class, $file);
            self::assertInstanceOf(MailCorrespondence::class, $mail);
            self::assertSame($reservation, $file->getReservation());
            self::assertSame($reservation, $mail->getReservation());
            self::assertSame($templateId, $file->getTemplate()?->getId());
            self::assertSame('Hydrated file', $file->getName());
            self::assertSame('document.pdf', $file->getFileName());
            self::assertSame('guest@example.com', $mail->getRecipient());
            self::assertSame('Subject', $mail->getSubject());
            self::assertSame($bytes, $file->getBinaryPayload());
            $collections = [
                $reservation->getCustomers(),
                $reservation->getInvoices(),
                $reservation->getCorrespondences(),
                $reservation->getRegistrationBookEntries(),
                $reservation->getPrices(),
                $mail->getChildren(),
                $file->getParents(),
            ];
            foreach ($collections as $collection) {
                self::assertInstanceOf(PersistentCollection::class, $collection);
                self::assertFalse($collection->isInitialized());
                self::assertFalse($collection->isDirty());
            }
            self::assertTrue($mail->getChildren()->contains($file));
            self::assertTrue($file->getParents()->contains($mail));
            self::assertTrue($reservation->getCorrespondences()->contains($file));
            self::assertTrue($reservation->getCorrespondences()->contains($mail));
            $uow = $em->getUnitOfWork();
            $uow->computeChangeSets();
            self::assertSame([], $uow->getEntityChangeSet($reservation));
            self::assertSame([], $uow->getEntityChangeSet($file));
            self::assertSame([], $uow->getEntityChangeSet($mail));
            self::assertSame([], $uow->getScheduledEntityUpdates());
            self::assertSame([], $uow->getScheduledCollectionUpdates());
            $em->flush();
        } finally {
            $em->clear();
            $connection->rollBack();
        }
    }
}
