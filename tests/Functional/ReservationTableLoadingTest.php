<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\CalendarSyncImport;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Repository\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

final class ReservationTableLoadingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DebugDataHolder $queries;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $queries = static::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $this->queries = $queries;
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        $this->em->clear();
        parent::tearDown();
    }

    /** @return iterable<string, array{bool, string}> */
    public static function viewsAndStatuses(): iterable
    {
        foreach ([false, true] as $yearly) {
            foreach (['blocking', 'non_blocking', 'all'] as $mode) {
                yield ($yearly ? 'yearly' : 'table').' '.$mode => [$yearly, $mode];
            }
        }
    }

    #[DataProvider('viewsAndStatuses')]
    public function testTableAndPopoversNeedNoQueriesAfterLoading(bool $yearly, string $mode): void
    {
        $fixture = $this->createFixture();
        $expected = $fixture['reservations'];
        unset($expected['conflict'], $expected['outside']);
        if ($yearly) {
            unset($expected['other_room']);
        }
        if ('blocking' === $mode) {
            unset($expected['canceled']);
        } elseif ('non_blocking' === $mode) {
            $expected = ['canceled' => $expected['canceled']];
        }
        $expectedIds = array_map(static fn (Reservation $r): int => $r->getId(), array_values($expected));
        $mainId = $fixture['reservations']['main']->getId();
        $roomIds = array_map(static fn (Appartment $room): int => $room->getId(), $fixture['rooms']);

        // Clear the identity map so the fixture cannot hide lazy association queries.
        $this->em->clear();
        $rooms = array_map(fn (int $id): Appartment => $this->em->find(Appartment::class, $id), $roomIds);
        $repository = static::getContainer()->get(ReservationRepository::class);
        $twig = static::getContainer()->get(Environment::class);
        // Global settings are a fixed page query, independent of the visible reservations.
        $twig->getGlobals();
        $template = $twig->load('Reservations/_resevation_popover.html.twig');
        $this->queries->reset();

        $start = new \DateTimeImmutable('2040-06-10');
        $end = new \DateTimeImmutable('2040-06-20');
        $reservations = $yearly
            ? $repository->loadReservationsForApartment($start, $end, $rooms[0], $mode)
            : $repository->loadReservationsForApartments($start, $end, $rooms, $mode);

        self::assertSame($expectedIds, array_map(static fn (Reservation $r): int => $r->getId(), $reservations));
        self::assertCount(1, $this->queries->getData()['default'] ?? []);
        $this->queries->reset();

        foreach ($reservations as $reservation) {
            $invoices = $reservation->getInvoices();
            self::assertInstanceOf(PersistentCollection::class, $invoices);
            self::assertTrue($invoices->isInitialized(), 'Even an empty invoice collection must be loaded.');
            $booker = $reservation->getBooker();
            if (null !== $booker) {
                $addresses = $booker->getCustomerAddresses();
                self::assertInstanceOf(PersistentCollection::class, $addresses);
                self::assertTrue($addresses->isInitialized(), 'Even an empty address collection must be loaded.');
            }

            // The grid reads both colors before Twig renders the popover title and body.
            $reservation->getReservationStatus()?->getColor();
            $reservation->getReservationStatus()?->getContrastColor();
            $reservation->getReservationOrigin()?->getColor();
            $template->renderBlock('popTitle', ['reservation' => $reservation]);
            $body = $template->renderBlock('popBody', ['reservation' => $reservation]);
            if ($reservation->getId() === $mainId) {
                self::assertCount(2, $invoices);
                self::assertNotNull($booker);
                self::assertCount(2, $booker->getCustomerAddresses());
                foreach (['POPOVER-A', 'POPOVER-B', 'private@example.invalid', 'office@example.invalid'] as $value) {
                    self::assertStringContainsString($value, $body);
                }
            }
        }

        self::assertCount(0, $this->queries->getData(), 'Rendering must not lazily load any associations.');
    }

    public function testEmptyRoomListDoesNotQuery(): void
    {
        $repository = static::getContainer()->get(ReservationRepository::class);
        $this->queries->reset();

        self::assertSame([], $repository->loadReservationsForApartments(
            new \DateTimeImmutable('2040-06-10'),
            new \DateTimeImmutable('2040-06-20'),
            [],
        ));
        self::assertCount(0, $this->queries->getData());
    }

    /** @return array{rooms: list<Appartment>, reservations: array<string, Reservation>} */
    private function createFixture(): array
    {
        $rooms = $this->em->getRepository(Appartment::class)->findBy(['active' => true], ['id' => 'ASC'], 2);
        self::assertCount(2, $rooms);
        $blocking = $this->createStatus('Popover blocking', true);
        $canceled = $this->createStatus('Popover canceled', false);
        $booker = $this->createBooker('With contacts');
        $withoutContacts = $this->createBooker('Without contacts');
        foreach (['private', 'office'] as $kind) {
            $address = new CustomerAddresses();
            $address->setType('CUSTOMER_ADDRESS_TYPE_'.('office' === $kind ? 'BUSINESS' : 'PRIVATE'));
            $address->setEmail($kind.'@example.invalid');
            $booker->addCustomerAddress($address);
            $this->em->persist($address);
        }
        $origin = new ReservationOrigin();
        $origin->setName('Popover origin');
        $origin->setColor('#336699');
        $this->em->persist($origin);
        $import = new CalendarSyncImport();
        $import->setName('Popover calendar');
        $import->setUrl('https://example.invalid/calendar.ics');
        $import->setApartment($rooms[0]);
        $import->setReservationStatus($blocking);
        $import->setReservationOrigin($origin);
        $this->em->persist($import);

        $reservations = [
            'ends_at_start' => $this->createReservation($rooms[0], $blocking, '08', '10'),
            'main' => $this->createReservation($rooms[0], $blocking, '11', '13'),
            'other_room' => $this->createReservation($rooms[1], $blocking, '12', '14'),
            'empty_contacts' => $this->createReservation($rooms[0], $blocking, '14', '15'),
            'imported' => $this->createReservation($rooms[0], $blocking, '15', '16'),
            'canceled' => $this->createReservation($rooms[0], $canceled, '17', '18'),
            'starts_at_end' => $this->createReservation($rooms[0], $blocking, '20', '22'),
            'conflict' => $this->createReservation($rooms[0], $blocking, '12', '13'),
            'outside' => $this->createReservation($rooms[0], $blocking, '22', '23'),
        ];
        foreach (['main', 'other_room', 'canceled'] as $key) {
            $reservations[$key]->setBooker($booker);
        }
        $reservations['main']->setReservationOrigin($origin);
        $reservations['empty_contacts']->setBooker($withoutContacts);
        $reservations['imported']->setCalendarSyncImport($import);
        $reservations['imported']->setReservationOrigin($origin);
        $reservations['conflict']->setIsConflict(true);
        foreach (['POPOVER-A', 'POPOVER-B'] as $number) {
            $invoice = new Invoice();
            $invoice->setNumber($number);
            $invoice->setDate(new \DateTime('2040-06-11'));
            $this->em->persist($invoice);
            $reservations['main']->addInvoice($invoice);
            // Shared bookers and invoices must not duplicate reservations or truncate collections.
            $reservations['other_room']->addInvoice($invoice);
        }
        $this->em->flush();

        return ['rooms' => $rooms, 'reservations' => $reservations];
    }

    private function createStatus(string $name, bool $blocking): ReservationStatus
    {
        $status = new ReservationStatus();
        $status->setName($name)->setColor('#336699')->setContrastColor('#ffffff')->setIsBlocking($blocking);
        $this->em->persist($status);

        return $status;
    }

    private function createBooker(string $lastname): Customer
    {
        $booker = new Customer();
        $booker->setSalutation('Frau');
        $booker->setFirstname('Popover');
        $booker->setLastname($lastname);
        $this->em->persist($booker);

        return $booker;
    }

    private function createReservation(Appartment $room, ReservationStatus $status, string $startDay, string $endDay): Reservation
    {
        $reservation = new Reservation();
        $reservation->setUuid(Uuid::v4());
        $reservation->setAppartment($room);
        $reservation->setReservationStatus($status);
        $reservation->setPersons(1);
        $reservation->setStartDate(new \DateTime('2040-06-'.$startDay));
        $reservation->setEndDate(new \DateTime('2040-06-'.$endDay));
        $this->em->persist($reservation);

        return $reservation;
    }
}
