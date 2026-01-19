<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\CalendarSyncImport;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Service\CalendarImportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Verify iCal import syncing and conflict handling. */
final class CalendarImportServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private static int $apartmentCounter = 1;

    /** Boot the kernel and prepare the entity manager. */
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
    }

    /** Ensure a valid iCal feed creates a reservation with import metadata. */
    public function testImportCreatesReservation(): void
    {
        $import = $this->createImport('https://example.test/ical/create', CalendarSyncImport::CONFLICT_MARK);
        $uid = 'uid-create-1';
        $start = new \DateTimeImmutable('+2 days');
        $end = new \DateTimeImmutable('+4 days');
        $service = $this->createServiceWithResponses([
            $import->getUrl() => $this->buildIcal($uid, $start, $end, 'Test description'),
        ]);

        $service->syncImport($import);

        $reservation = $this->getReservationRepository()->findOneByRefUidAndImport($uid, $import);
        self::assertNotNull($reservation);
        self::assertFalse($reservation->isConflict());
        self::assertSame('Test description', $reservation->getRemark());
        self::assertSame($import->getReservationOrigin()->getId(), $reservation->getReservationOrigin()->getId());
        self::assertSame($import->getReservationStatus()->getId(), $reservation->getReservationStatus()->getId());
        self::assertSame($start->format('Y-m-d'), $reservation->getStartDate()->format('Y-m-d'));
        self::assertSame($end->format('Y-m-d'), $reservation->getEndDate()->format('Y-m-d'));
    }

    /** Verify that an existing reservation is updated when the UID matches. */
    public function testImportUpdatesReservationWhenUidMatches(): void
    {
        $import = $this->createImport('https://example.test/ical/update', CalendarSyncImport::CONFLICT_MARK);
        $uid = 'uid-update-1';
        $oldStart = new \DateTime('+1 day');
        $oldEnd = new \DateTime('+2 days');
        $reservation = $this->createReservation($import, $uid, $oldStart, $oldEnd);
        $newStart = new \DateTimeImmutable('+3 days');
        $newEnd = new \DateTimeImmutable('+5 days');
        $service = $this->createServiceWithResponses([
            $import->getUrl() => $this->buildIcal($uid, $newStart, $newEnd, 'Updated description'),
        ]);

        $service->syncImport($import);

        $this->em->refresh($reservation);
        self::assertSame($newStart->format('Y-m-d'), $reservation->getStartDate()->format('Y-m-d'));
        self::assertSame($newEnd->format('Y-m-d'), $reservation->getEndDate()->format('Y-m-d'));
        self::assertSame('Updated description', $reservation->getRemark());
        self::assertFalse($reservation->isConflict());
    }

    /** Verify that the mark strategy creates a conflict reservation for overlaps. */
    public function testConflictStrategyMarkCreatesConflictReservation(): void
    {
        $import = $this->createImport('https://example.test/ical/mark', CalendarSyncImport::CONFLICT_MARK);
        $this->createReservation($import, 'uid-existing-1', new \DateTime('+2 days'), new \DateTime('+4 days'));
        $uid = 'uid-mark-1';
        $start = new \DateTimeImmutable('+3 days');
        $end = new \DateTimeImmutable('+5 days');
        $service = $this->createServiceWithResponses([
            $import->getUrl() => $this->buildIcal($uid, $start, $end),
        ]);

        $service->syncImport($import);

        $reservation = $this->getReservationRepository()->findOneByRefUidAndImport($uid, $import);
        self::assertNotNull($reservation);
        self::assertTrue($reservation->isConflict());
        self::assertFalse($reservation->isConflictIgnored());
    }

    /** Verify that the skip strategy ignores conflicting events. */
    public function testConflictStrategySkipIgnoresConflictingReservation(): void
    {
        $import = $this->createImport('https://example.test/ical/skip', CalendarSyncImport::CONFLICT_SKIP);
        $this->createReservation($import, 'uid-existing-2', new \DateTime('+2 days'), new \DateTime('+4 days'));
        $uid = 'uid-skip-1';
        $start = new \DateTimeImmutable('+3 days');
        $end = new \DateTimeImmutable('+5 days');
        $service = $this->createServiceWithResponses([
            $import->getUrl() => $this->buildIcal($uid, $start, $end),
        ]);

        $service->syncImport($import);

        $reservation = $this->getReservationRepository()->findOneByRefUidAndImport($uid, $import);
        self::assertNull($reservation);
    }

    /** Verify that the overwrite strategy marks conflicts and stores the new reservation. */
    public function testConflictStrategyOverwriteMarksExistingAndCreatesNew(): void
    {
        $import = $this->createImport('https://example.test/ical/overwrite', CalendarSyncImport::CONFLICT_OVERWRITE);
        $existing = $this->createReservation($import, 'uid-existing-3', new \DateTime('+2 days'), new \DateTime('+4 days'));
        $uid = 'uid-overwrite-1';
        $start = new \DateTimeImmutable('+3 days');
        $end = new \DateTimeImmutable('+5 days');
        $service = $this->createServiceWithResponses([
            $import->getUrl() => $this->buildIcal($uid, $start, $end),
        ]);

        $service->syncImport($import);

        $this->em->refresh($existing);
        self::assertTrue($existing->isConflict());
        $reservation = $this->getReservationRepository()->findOneByRefUidAndImport($uid, $import);
        self::assertNotNull($reservation);
        self::assertFalse($reservation->isConflict());
    }

    /** Build a CalendarImportService with mocked HTTP responses keyed by URL. */
    private function createServiceWithResponses(array $responses): CalendarImportService
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($responses) {
            return new MockResponse($responses[$url] ?? '', ['http_code' => 200]);
        });
        $cache = static::getContainer()->get(CacheInterface::class);
        $translator = static::getContainer()->get(TranslatorInterface::class);

        return new CalendarImportService($this->em, $httpClient, $cache, $translator);
    }

    /** Persist a calendar import with required relations. */
    private function createImport(string $url, string $strategy): CalendarSyncImport
    {
        $import = new CalendarSyncImport();
        $import->setName('Test Import');
        $import->setUrl($url);
        $import->setIsActive(true);
        $import->setConflictStrategy($strategy);
        $import->setApartment($this->createApartment());
        $import->setReservationOrigin($this->getAnyOrigin());
        $import->setReservationStatus($this->getAnyStatus());
        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    /** Persist a reservation that can be used as a conflict baseline. */
    private function createReservation(
        CalendarSyncImport $import,
        string $uid,
        \DateTime $start,
        \DateTime $end
    ): Reservation {
        $reservation = new Reservation();
        $reservation->setAppartment($import->getApartment());
        $reservation->setStartDate($start);
        $reservation->setEndDate($end);
        $reservation->setPersons(1);
        $reservation->setReservationOrigin($import->getReservationOrigin());
        $reservation->setReservationStatus($import->getReservationStatus());
        $reservation->setRefUid($uid);
        $reservation->setCalendarSyncImport($import);
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setUuid(\Symfony\Component\Uid\Uuid::v4());
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }

    /** Build a minimal iCal payload for a single event. */
    private function buildIcal(
        string $uid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?string $description = null
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$start->format('Ymd'),
            'DTSTART:'.$start->format('Ymd'),
            'DTEND:'.$end->format('Ymd'),
        ];
        if (null !== $description) {
            $lines[] = 'DESCRIPTION:'.$description;
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\n", $lines);
    }

    /** Create a dedicated apartment to isolate reservation conflicts. */
    private function createApartment(): Appartment
    {
        $apartment = new Appartment();
        $apartment->setNumber($this->buildApartmentNumber());
        $apartment->setBedsMax(2);
        $apartment->setDescription('Test Apartment');
        $apartment->setObject($this->getAnySubsidiary());
        $this->em->persist($apartment);
        $this->em->flush();

        return $apartment;
    }

    /** Build a unique apartment number for tests. */
    private function buildApartmentNumber(): string
    {
        $number = 'T'.str_pad((string) self::$apartmentCounter, 3, '0', STR_PAD_LEFT);
        self::$apartmentCounter++;

        return $number;
    }

    /** Fetch any existing subsidiary from fixtures. */
    private function getAnySubsidiary(): \App\Entity\Subsidiary
    {
        $subsidiary = $this->em->getRepository(\App\Entity\Subsidiary::class)->findOneBy([]);
        self::assertNotNull($subsidiary, 'A subsidiary must exist in fixtures.');

        return $subsidiary;
    }

    /** Fetch any existing reservation origin from fixtures. */
    private function getAnyOrigin(): ReservationOrigin
    {
        $origin = $this->em->getRepository(ReservationOrigin::class)->findOneBy([]);
        self::assertNotNull($origin, 'A reservation origin must exist in fixtures.');

        return $origin;
    }

    /** Fetch any existing reservation status from fixtures. */
    private function getAnyStatus(): ReservationStatus
    {
        $status = $this->em->getRepository(ReservationStatus::class)->findOneBy([]);
        self::assertNotNull($status, 'A reservation status must exist in fixtures.');

        return $status;
    }

    /** Resolve the reservation repository for assertions. */
    private function getReservationRepository(): \App\Repository\ReservationRepository
    {
        /** @var \App\Repository\ReservationRepository $repository */
        $repository = $this->em->getRepository(Reservation::class);

        return $repository;
    }
}
