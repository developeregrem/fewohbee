<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\BookingRestrictionRule;
use App\Entity\Enum\BookingRestrictionType as Type;
use App\Entity\RoomCategory;
use App\Repository\BookingRestrictionRuleRepository;
use App\Service\OnlineBooking\BookingRestrictionCalendar;
use App\Service\OnlineBooking\BookingRestrictionPresentation;
use App\Service\OnlineBooking\BookingRestrictionService;
use App\Service\OperationsFilterService;
use App\Service\ReservationPeriodService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The settings calendar arranges resolved restrictions over four weeks and adds them up per
 * arrival day. Checked against the real translation file, because its wording is the explanation.
 */
final class BookingRestrictionCalendarTest extends TestCase
{
    public function testShowsFourWeeksFromTheMondayOfTheRequestedWeek(): void
    {
        $view = $this->calendar([])->build(new RoomCategory(), '2026-09-17');

        self::assertSame('2026-09-14', $view['weekStart']->format('Y-m-d'));
        self::assertCount(BookingRestrictionCalendar::DAYS, $view['days']);
        self::assertCount(BookingRestrictionCalendar::DAYS, $view['arrivals']);
        self::assertSame('2026-09-14', $view['days'][0]->date->format('Y-m-d'));
        self::assertSame('2026-10-11', $view['days'][BookingRestrictionCalendar::DAYS - 1]->date->format('Y-m-d'));
        self::assertSame('2026-09-07', $view['previousWeek']->format('Y-m-d'));
        self::assertSame('2026-09-21', $view['nextWeek']->format('Y-m-d'));
        // 14.–30. September and 1.–11. October.
        self::assertSame([17, 11], array_column($view['months'], 'span'));
        // Without rules every arrival is bookable from one night and nothing needs explaining.
        self::assertSame(['nights' => 1, 'raised' => false, 'gap' => false, 'value' => 'ab einer Nacht buchbar', 'reasons' => []], $view['arrivals'][0]);
    }

    public function testDefaultsToTheCurrentWeek(): void
    {
        $view = $this->calendar([])->build(new RoomCategory(), null);
        $today = new \DateTimeImmutable('today');

        self::assertSame('1', $view['weekStart']->format('N'));
        self::assertLessThanOrEqual($today, $view['weekStart']);
        self::assertGreaterThan($today, $view['weekStart']->modify('+7 days'));
    }

    /** The case that confused in practice: a Wednesday arrival needs three nights because of Thursday's night. */
    public function testMarksAnArrivalALaterNightRaisesAndNamesThatNight(): void
    {
        $general = $this->rule(1, Type::MIN_STAY_ARRIVAL, 2);
        $weekend = $this->rule(4, Type::MIN_STAY_THROUGH, 3, [4, 5, 6]);
        $weekend->setPeriod(new \DateTimeImmutable('2026-09-24'), new \DateTimeImmutable('2026-09-27'));

        $view = $this->calendar([$general, $weekend])->build(new RoomCategory(), '2026-09-14');
        $wednesday = $view['arrivals'][9];

        self::assertSame(3, $wednesday['nights']);
        self::assertTrue($wednesday['raised']);
        self::assertFalse($wednesday['gap']);
        self::assertSame('ab 3 Nächten buchbar', $wednesday['value']);
        // One night fails by the day's own arrival rule, which the row below already shows.
        self::assertSame([
            ['label' => '2 Nächte nicht buchbar', 'text' => 'Die Nacht Do → Fr (24.09.) verlangt insgesamt mindestens 3 Nächte.', 'source' => 'Sonderzeitraum 24.09.2026 – 26.09.2026'],
        ], $wednesday['reasons']);

        // Two nights from Tuesday end before the Thursday night.
        self::assertSame(2, $view['arrivals'][8]['nights']);
        self::assertFalse($view['arrivals'][8]['raised']);
    }

    public function testMarksAGapWhenALongerStayReachesAStricterNight(): void
    {
        $view = $this->calendar([
            $this->rule(1, Type::MIN_STAY_THROUGH, 4, [1, 2, 3, 4]),
            $this->rule(2, Type::MIN_STAY_THROUGH, 2, [5, 6, 7]),
        ])->build(new RoomCategory(), '2026-09-14');
        $saturday = $view['arrivals'][5];

        // The Saturday night itself asks for two, so two is expected; three reach into the Monday night.
        self::assertSame(2, $saturday['nights']);
        self::assertFalse($saturday['raised']);
        self::assertTrue($saturday['gap']);
        self::assertSame([
            ['label' => '3 Nächte nicht buchbar', 'text' => 'Die Nacht Mo → Di (21.09.) verlangt insgesamt mindestens 4 Nächte.', 'source' => 'Allgemeine Regel'],
        ], $saturday['reasons']);
    }

    public function testADepartureClosureRaisesTheArrivalBeforeItButOpensNoGap(): void
    {
        $view = $this->calendar([
            $this->rule(1, Type::MIN_STAY_ARRIVAL, 2),
            $this->rule(2, Type::CLOSED_TO_DEPARTURE, null, [7]),
        ])->build(new RoomCategory(), '2026-09-14');
        $friday = $view['arrivals'][4];

        self::assertSame(3, $friday['nights']);
        self::assertTrue($friday['raised']);
        // Nine nights end on a Sunday again; that closure has its own row and is no gap.
        self::assertFalse($friday['gap']);
        self::assertSame([
            ['label' => '2 Nächte nicht buchbar', 'text' => 'Abreise am So, 20.09. ist nicht möglich.', 'source' => 'Allgemeine Regel'],
        ], $friday['reasons']);
    }

    public function testClosedArrivalShowsNoLengthAndItsRuleComesFromTheRuleMap(): void
    {
        $general = $this->rule(1, Type::MIN_STAY_ARRIVAL, 2);
        $sundayClosed = $this->rule(7, Type::CLOSED_TO_ARRIVAL, null, [7]);

        $view = $this->calendar([$general, $sundayClosed])->build(new RoomCategory(), '2026-09-14');

        self::assertSame(['nights' => null, 'raised' => false, 'gap' => false, 'value' => 'keine Anreise möglich', 'reasons' => []], $view['arrivals'][6]);
        self::assertSame([1, 7], array_keys($view['rules']));
        self::assertSame('Allgemeine Regel', $view['rules'][1]['source']);
        self::assertSame('Am So ist keine Anreise möglich.', $view['rules'][7]['text']);
    }

    /** @param list<BookingRestrictionRule> $rules */
    private function calendar(array $rules): BookingRestrictionCalendar
    {
        $repository = $this->createStub(BookingRestrictionRuleRepository::class);
        $repository->method('findActiveForPeriod')->willReturn($rules);
        $repository->method('findForSettings')->willReturn($rules);

        $translator = new Translator('de');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', dirname(__DIR__, 2).'/translations/BookingRules/messages.de.yaml', 'de');

        return new BookingRestrictionCalendar(
            new BookingRestrictionService($repository, new ReservationPeriodService()),
            new BookingRestrictionPresentation($translator, $repository),
            new OperationsFilterService(),
            $translator,
        );
    }

    /** @param list<int> $weekdays */
    private function rule(int $id, Type $type, ?int $nights, array $weekdays = [1, 2, 3, 4, 5, 6, 7]): BookingRestrictionRule
    {
        $rule = new BookingRestrictionRule();
        (new \ReflectionProperty(BookingRestrictionRule::class, 'id'))->setValue($rule, $id);
        $rule->setType($type);
        $rule->setMinNights($nights);
        $rule->setWeekdays($weekdays);

        return $rule;
    }
}
