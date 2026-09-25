<?php

declare(strict_types=1);

namespace App\Tests\Unit\GuestCheckIn;

use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\GuestCheckInPolicy;
use App\Service\GuestCheckIn\Section\GuestCheckInSection;
use App\Service\GuestCheckIn\Section\GuestCheckInSectionProviderInterface;
use App\Service\GuestCheckIn\Section\GuestCheckInSections;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GuestCheckInSectionsTest extends TestCase
{
    public function testSensitiveSectionsOnlyAppearAfterCheckInAndDuringTheStay(): void
    {
        $provider = new class implements GuestCheckInSectionProviderInterface {
            public function getSections(GuestCheckIn $checkIn): iterable
            {
                yield new GuestCheckInSection('always', 'a.html.twig');
                yield new GuestCheckInSection('door_code', 'b.html.twig', requiresSubmission: true, duringStayOnly: true);
            }
        };
        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-10-10'));
        $reservation->setEndDate(new \DateTime('2026-10-12'));
        $checkIn = new GuestCheckIn($reservation, 'selector');

        self::assertSame(['always'], $this->keys($this->sections($provider, '2026-10-05 12:00')->visibleFor($checkIn)));

        $checkIn->recordSubmission(['v' => 1], new \DateTimeImmutable('2026-10-05'));
        self::assertSame(['always'], $this->keys($this->sections($provider, '2026-10-09 23:59')->visibleFor($checkIn)), 'Checked in, but the stay has not started.');
        self::assertSame(['always', 'door_code'], $this->keys($this->sections($provider, '2026-10-10 08:00')->visibleFor($checkIn)));
        self::assertSame(['always'], $this->keys($this->sections($provider, '2026-10-13 08:00')->visibleFor($checkIn)), 'Stay is over.');
    }

    private function sections(GuestCheckInSectionProviderInterface $provider, string $now): GuestCheckInSections
    {
        return new GuestCheckInSections([$provider], new GuestCheckInPolicy(new MockClock($now, date_default_timezone_get())));
    }

    /**
     * @param list<GuestCheckInSection> $sections
     *
     * @return list<string>
     */
    private function keys(array $sections): array
    {
        return array_map(static fn (GuestCheckInSection $section): string => $section->key, $sections);
    }
}
