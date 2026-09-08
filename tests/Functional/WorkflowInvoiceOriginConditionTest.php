<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Subsidiary;
use App\Workflow\Condition\InvoiceReservationOriginCondition;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The condition reads Invoice::getReservations(), which is the inverse side of the
 * reservation <-> invoice association: it is only ever filled from the database.
 * InvoiceServiceController links the two through Reservation::addInvoice() and
 * refreshes the invoice before dispatching InvoiceCreatedEvent, so this test walks
 * exactly that path against the real schema.
 */
final class WorkflowInvoiceOriginConditionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        self::bootKernel();

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
    }

    public function testConditionSeesTheOriginOfAnInvoiceJustCreated(): void
    {
        [$invoice, $originId] = $this->createInvoiceWithReservation();
        $condition = new InvoiceReservationOriginCondition();

        self::assertTrue(
            $condition->evaluate(['originId' => $originId], $invoice, []),
            'The origin must be visible on the invoice the moment InvoiceCreatedEvent is dispatched.'
        );
        self::assertFalse($condition->evaluate(['originId' => $originId + 1000], $invoice, []));
    }

    public function testConditionSeesTheOriginOnAnInvoiceLoadedLater(): void
    {
        [$invoice, $originId] = $this->createInvoiceWithReservation();
        $invoiceId = $invoice->getId();

        $this->em->clear();
        $reloaded = $this->em->getRepository(Invoice::class)->find($invoiceId);
        self::assertInstanceOf(Invoice::class, $reloaded);

        $condition = new InvoiceReservationOriginCondition();

        self::assertTrue($condition->evaluate(['originId' => $originId], $reloaded, []),
            'Lazily loaded reservations must be traversed as well.');
    }

    /** @return array{0: Invoice, 1: int} */
    private function createInvoiceWithReservation(): array
    {
        $suffix = bin2hex(random_bytes(2));

        $subsidiary = new Subsidiary();
        $subsidiary->setName('Origin Test '.$suffix);
        $subsidiary->setDescription('Test');
        $this->em->persist($subsidiary);

        $appartment = new Appartment();
        $appartment->setNumber('OT-'.$suffix);
        $appartment->setBedsMax(2);
        $appartment->setDescription('Test room');
        $appartment->setObject($subsidiary);
        $this->em->persist($appartment);

        $origin = new ReservationOrigin();
        $origin->setName('Portal '.$suffix);
        $this->em->persist($origin);

        $status = new ReservationStatus();
        $status->setName('Confirmed '.$suffix);
        $status->setColor('#00aa00');
        $status->setContrastColor('#ffffff');
        $this->em->persist($status);

        $customer = new Customer();
        $customer->setSalutation('Mr');
        $customer->setFirstname('Test');
        $customer->setLastname('Customer');
        $this->em->persist($customer);

        $address = new CustomerAddresses();
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setCountry('DE');
        $this->em->persist($address);
        $customer->addCustomerAddress($address);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);
        $reservation->setReservationStatus($status);
        $reservation->setBooker($customer);
        $reservation->addCustomer($customer);
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime('2026-05-01'));
        $reservation->setEndDate(new \DateTime('2026-05-05'));
        $reservation->setAppartment($appartment);
        $reservation->setReservationDate(new \DateTime('2026-04-01'));
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setUuid(Uuid::v4());
        $this->em->persist($reservation);

        $invoice = new Invoice();
        $invoice->setNumber('OT-'.$suffix);
        $invoice->setDate(new \DateTime('2026-05-06'));
        $invoice->setStatus(1);
        $this->em->persist($invoice);

        // Owning side only, exactly like InvoiceServiceController does on create.
        $reservation->addInvoice($invoice);

        $this->em->flush();
        $this->em->refresh($invoice);

        return [$invoice, (int) $origin->getId()];
    }
}
