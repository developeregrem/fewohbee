<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\RoomCategoryKind;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Entity\User;
use App\Service\ReservationObject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Event spaces are booked by the day in the admin: "to" is the last day and is stored as the day
 * after, the plan shows whole days and a booking keeps its kind of room.
 */
final class EventSpaceReservationTest extends WebTestCase
{
    private const CATEGORY = 'event-res-test hall';
    private const HALL = 'EVT-HALL';
    private const DAY = '2041-05-04';
    private const NEXT_DAY = '2041-05-05';

    public function testTableSelectionBooksTheSelectedDay(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->hall();

        $client->request('POST', '/reservation/appartments/selectable/add/to/reservation', [
            'createNewReservation' => 'true',
            'appartmentid' => (string) $hall->getId(),
            'from' => self::DAY,
            'end' => self::DAY,
        ]);

        self::assertResponseIsSuccessful();
        $draft = $this->selection($client)[0];
        self::assertSame(self::DAY, $draft->getStart());
        self::assertSame(self::NEXT_DAY, $draft->getEnd());
        self::assertSelectorTextContains('#selectedAppartments', '04.05.2041 – 04.05.2041');
        self::assertSelectorTextContains('#selectedAppartments', $this->trans('reservation.summary.days', ['%count%' => 1]));
    }

    public function testAddingFromTheListReadsToAsTheLastDay(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->hall();

        $client->request('POST', '/reservation/appartments/add/to/reservation', [
            'appartmentid' => (string) $hall->getId(),
            'from' => self::DAY,
            'end' => self::NEXT_DAY,
            'status' => (string) $this->reservationStatus()->getId(),
            'persons' => '40',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('2041-05-06', $this->selection($client)[0]->getEnd());
    }

    public function testABookedDayIsNotOfferedAgainButTheNextDayIs(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->hall();
        $this->reservation($hall, self::DAY, self::NEXT_DAY);

        $client->request('POST', '/reservation/appartments/available/get', ['from' => self::DAY, 'end' => self::DAY, 'object' => 'all']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(sprintf('[data-appartment-id="%d"]', $hall->getId()));

        $client->request('POST', '/reservation/appartments/available/get', ['from' => self::NEXT_DAY, 'end' => self::NEXT_DAY, 'object' => 'all']);
        self::assertSelectorExists(sprintf('[data-appartment-id="%d"]', $hall->getId()));
        self::assertSelectorTextContains('.list-group', $this->trans('reservation.event_space.period', [
            '%days%' => $this->trans('reservation.summary.days', ['%count%' => 1]),
            '%date%' => '05.05.2041',
        ]));
    }

    public function testEditShowsAndTakesTheLastDay(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->hall();
        $reservation = $this->reservation($hall, self::DAY, self::NEXT_DAY);

        $crawler = $client->request('GET', '/reservation/edit/'.$reservation->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(self::DAY, $crawler->filter('#end')->attr('value'));

        $client->request('POST', '/reservation/edit/'.$reservation->getId(), $this->editValues($hall, self::NEXT_DAY));

        self::assertResponseIsSuccessful();
        self::assertSame('2041-05-06', $this->reload($reservation)->getEndDate()->format('Y-m-d'));
    }

    public function testEditAndMoveRefuseARoomOfTheOtherKind(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->hall();
        $reservation = $this->reservation($hall, self::DAY, self::NEXT_DAY);
        $room = $this->accommodation();

        $client->request('POST', '/reservation/edit/'.$reservation->getId(), $this->editValues($room, self::DAY));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $this->trans('reservation.move.other_kind'));
        self::assertSame($hall->getId(), $this->reload($reservation)->getAppartment()?->getId());

        $crawler = $client->request('GET', '/reservation/table', ['start' => '2041-05-01', 'interval' => 7]);
        $token = (string) $crawler->filter('[data-reservation-move-token]')->attr('data-reservation-move-token');
        $client->request('POST', sprintf('/reservation/%d/move', $reservation->getId()), [
            '_token' => $token,
            'apartmentId' => (string) $room->getId(),
            'startDate' => self::DAY,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame($this->trans('reservation.move.other_kind'), json_decode((string) $client->getResponse()->getContent(), true)['message'] ?? null);
        self::assertSame($hall->getId(), $this->reload($reservation)->getAppartment()?->getId());
    }

    public function testPlanShowsTheEventAsAWholeDay(): void
    {
        $client = $this->authenticatedClient();
        $hall = $this->hall();
        $this->reservation($hall, self::DAY, self::NEXT_DAY);

        $crawler = $client->request('GET', '/reservation/table', ['start' => '2041-05-01', 'interval' => 7]);

        self::assertResponseIsSuccessful();
        $cell = $crawler->filter(sprintf('tr[data-appartment="%d"][data-event-space="1"] td.reservation', $hall->getId()));
        self::assertCount(1, $cell);
        // Both halves of the day: the event starts at the day boundary
        self::assertSame('2', $cell->attr('colspan'));
        self::assertStringContainsString('day-start', (string) $cell->attr('class'));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $em->clear();
                foreach ($em->getRepository(Appartment::class)->findBy(['number' => self::HALL]) as $hall) {
                    foreach ($em->getRepository(Reservation::class)->findBy(['appartment' => $hall]) as $reservation) {
                        $em->remove($reservation);
                    }
                    $em->remove($hall);
                }
                $em->flush();
                foreach ($em->getRepository(RoomCategory::class)->findBy(['name' => self::CATEGORY]) as $category) {
                    $em->remove($category);
                }
                $em->flush();
            }
        }
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function editValues(Appartment $room, string $lastDayOrDeparture): array
    {
        return [
            'aid' => (string) $room->getId(),
            'status' => (string) $this->reservationStatus()->getId(),
            'persons' => '2',
            'guestCounts' => '{}',
            'from' => self::DAY,
            'end' => $lastDayOrDeparture,
        ];
    }

    private function hall(): Appartment
    {
        $em = $this->em();
        $category = (new RoomCategory())->setName(self::CATEGORY)->setKind(RoomCategoryKind::EVENT_SPACE);
        $em->persist($category);
        $hall = new Appartment();
        $hall->setNumber(self::HALL);
        $hall->setDescription('Festsaal');
        $hall->setBedsMax(60);
        $hall->setObject($em->getRepository(Subsidiary::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('Sample subsidiary missing.'));
        $hall->setRoomCategory($category);
        $em->persist($hall);
        $em->flush();

        return $hall;
    }

    private function accommodation(): Appartment
    {
        foreach ($this->em()->getRepository(Appartment::class)->findBy(['active' => true], ['id' => 'ASC']) as $room) {
            if (!$room->isEventSpace() && $room->getBedsMax() >= 2) {
                return $room;
            }
        }

        throw new \RuntimeException('Sample accommodation missing.');
    }

    private function reservation(Appartment $room, string $start, string $end): Reservation
    {
        $reservation = new Reservation();
        $reservation->setUuid(Uuid::v4());
        $reservation->setAppartment($room);
        $reservation->setReservationStatus($this->reservationStatus());
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setReservationDate(new \DateTime('2041-01-10'));
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $reservation;
    }

    private function reload(Reservation $reservation): Reservation
    {
        $this->em()->clear();

        return $this->em()->find(Reservation::class, $reservation->getId()) ?? throw new \RuntimeException('Reservation vanished.');
    }

    private function reservationStatus(): ReservationStatus
    {
        return $this->em()->getRepository(ReservationStatus::class)->findOneBy(['code' => null], ['id' => 'ASC'])
            ?? throw new \RuntimeException('Sample reservation status missing.');
    }

    /** @return array<ReservationObject> */
    private function selection(KernelBrowser $client): array
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie);
        $session->setId($cookie->getValue());
        $session->start();
        $selection = $session->get('reservationInCreation');
        $session->save();
        self::assertIsArray($selection);

        return array_values($selection);
    }

    /** @param array<string, mixed> $params */
    private function trans(string $key, array $params = []): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, $params);
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
