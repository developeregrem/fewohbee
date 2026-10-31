<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Twig;

use App\Entity\Appartment;
use App\Entity\Reservation;
use App\Entity\RoomBlock;
use App\Service\AppSettingsService;
use App\Service\Calendar\PublicHolidayService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Yasumi\Holiday;

class AppTwigExtensions extends AbstractExtension implements GlobalsInterface
{
    private EntityManagerInterface $em;
    private RequestStack $requestStack;
    private PublicHolidayService $calendarService;
    private AppSettingsService $appSettingsService;

    public function __construct(EntityManagerInterface $em, RequestStack $requestStack, PublicHolidayService $cs, AppSettingsService $appSettingsService)
    {
        $this->em = $em;
        $this->requestStack = $requestStack;
        $this->calendarService = $cs;
        $this->appSettingsService = $appSettingsService;
    }

    public function getGlobals(): array
    {
        $settings = $this->appSettingsService->getSettings();

        return [
            'app_settings' => $settings,
            'customer_salutations' => $settings->getCustomerSalutations(),
            'currency' => $settings->getCurrency(),
            'currency_symbol' => $settings->getCurrencySymbol(),
        ];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('toBase64', 'base64_encode'), new TwigFilter('toHex', 'bin2hex')];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('date_difference', [$this, 'dateDifferenceFilter']),
            new TwigFunction('reservation_date_compare', [$this, 'reservationDateCompareFilter']),
            new TwigFunction('get_reservations_for_period', [$this, 'getReservationsForPeriodFilter']),
            new TwigFunction('get_room_blocks_for_period', [$this, 'getRoomBlocksForPeriodFilter']),
            new TwigFunction('getRoomBlocksForDay', [$this, 'getRoomBlocksForDay']),
            new TwigFunction('is_single_reservation_for_day', [$this, 'isSingleReservationForDayFilter']),
            new TwigFunction('get_letter_count_for_display', [$this, 'getLetterCountForDisplayFilter']),
            new TwigFunction('get_date_diff_amount', [$this, 'getDateDiffAmountFilter']),
            new TwigFunction('is_decimal_place_0', [$this, 'isDecimalPlace0']),
            new TwigFunction('getLocalizedMonth', [$this, 'getLocalizedMonthFilter']),
            new TwigFunction('getActiveRouteName', [$this, 'getActiveRouteNameFilter']),
            new TwigFunction('getLocalizedDate', [$this, 'getLocalizedDateFilter']),
            new TwigFunction('existsById', [$this, 'existsById']),
            new TwigFunction('getPublicdaysForDay', [$this, 'getPublicdaysForDay']),
            new TwigFunction('calendar_accent_marker_style', [$this, 'getCalendarAccentMarkerStyle']),
            new TwigFunction('getReservationsForDay', [$this, 'getReservationsForDay']),
            new TwigFunction('timestamp2UTC', [$this, 'timestamp2UTC']),
            new TwigFunction('date2UTC', [$this, 'date2UTC']),
            new TwigFunction('getReservationsMultipleOccupancy', [$this, 'getReservationsMultipleOccupancy']),
        ];
    }

    public function dateDifferenceFilter(string $startDate, string $endDate): int
    {
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $interval = date_diff($start, $end);

        return max(1, (int) $interval->format('%a'));
    }

    /**
     * compares the reservation start and end date with the displayed period. if start or end lies inside displayed period or not.
     * The object exposes getStartDate() and getEndDate(); the date is a Unix timestamp.
     */
    public function reservationDateCompareFilter(int $date, object $reservation, string $type = 'start'): bool
    {
        if ('start' == $type && $reservation->getStartDate()->getTimestamp() < $date) {
            return true;
        } elseif ('end' == $type && $reservation->getEndDate()->getTimestamp() > $date) {
            return true;
        } else {
            return false;
        }
    }

    /** @return list<Reservation> */
    public function getReservationsForPeriodFilter(int $today, int $intervall, Appartment $apartment): array
    {
        $start = new \DateTime(date('Y-m-d', $today));
        $end = new \DateTime(date('Y-m-d', $today + ($intervall * 3600 * 24)));
        $showCanceledOnly = (bool) $this->requestStack->getSession()->get('reservation-overview-show-canceled', false);
        $statusMode = $showCanceledOnly ? 'non_blocking' : 'blocking';

        return $this->em->getRepository(Reservation::class)->loadReservationsForApartment($start, $end, $apartment, $statusMode);
    }

    /**
     * Load room blocks overlapping the given period for one apartment (yearly view).
     *
     * @return RoomBlock[]
     */
    public function getRoomBlocksForPeriodFilter(int $today, int $intervall, Appartment $apartment): array
    {
        $start = new \DateTime(date('Y-m-d', $today));
        $end = new \DateTime(date('Y-m-d', $today + ($intervall * 3600 * 24)));

        return $this->em->getRepository(RoomBlock::class)->findForApartments($start, $end, [$apartment]);
    }

    /**
     * Room blocks that cover the given day. endDate is exclusive, but the block is still
     * shown on its endDate (left half) to mirror the reservation checkout rendering.
     *
     * @param RoomBlock[] $blocks
     *
     * @return RoomBlock[]
     */
    public function getRoomBlocksForDay(\DateTimeInterface $day, array $blocks): array
    {
        $result = [];
        foreach ($blocks as $block) {
            $start = new \DateTimeImmutable($block->getStartDate()->format('Y-m-d').' UTC');
            $end = new \DateTimeImmutable($block->getEndDate()->format('Y-m-d').' UTC');
            // An event space is shown by the day: the room is free again on the end day.
            if ($block->getAppartment()->isEventSpace() && $end > $start) {
                $end = $end->modify('-1 day');
            }
            if ($day >= $start && $day <= $end) {
                $result[] = $block;
            }
        }

        return $result;
    }

    /** @param array<int, Reservation> $reservations */
    public function isSingleReservationForDayFilter(int $today, int $period, int $reservationIdx, array $reservations, string $type = 'start'): bool
    {
        /* @var $currentReservation Reservation */
        $currentReservation = $reservations[$reservationIdx];
        if ('end' == $type) {
            $compareReservationIdx = $reservationIdx + 1;
            // wenn es eine nachfolgende reservierung gibt und diese nicht am gleichen tag startet wie die andere endet
            if (array_key_exists($compareReservationIdx, $reservations)
                && $reservations[$compareReservationIdx]->getStartDate()->getTimestamp() == $currentReservation->getEndDate()->getTimestamp()
            ) {
                return false;
            } else { // entweder es gibt keine nachfolgende oder am gleichen tag beginnt keine neue reservierung
                $periodEnd = $this->timestamp2UTC($today + ($period * 3600 * 24));
                $end = $this->date2UTC($currentReservation->getEndDate());
                // wenn das ende innerhalb des anzeigezeitraumes liegt
                if ($end <= $periodEnd) {
                    return true;
                } else {
                    return false;
                }
            }
        } else {
            $compareReservationIdx = $reservationIdx - 1;
            $tmpStart = clone $currentReservation->getStartDate();
            // wenn es eine vorherige reservierung gibt und diese nicht am gleichen tag endet wie die andere startet
            if (array_key_exists($compareReservationIdx, $reservations)
                && $reservations[$compareReservationIdx]->getEndDate()->getTimestamp() == $currentReservation->getStartDate()->getTimestamp()
            ) {
                return false;
            } else { // entweder es gibt keine vorherige oder am gleichen tag endet keine neue reservierung
                $periodStart = $this->timestamp2UTC($today);
                $start = $this->date2UTC($currentReservation->getStartDate());
                // wenn der start innerhalb des anzeigezeitraumes liegt
                if ($start >= $periodStart) {
                    return true;
                } else {
                    return false;
                }
            }
        }
    }

    /** The legacy second argument is unused and accepts any value. */
    public function getLetterCountForDisplayFilter(int $period, mixed $intervall): int|float
    {
        if ($period > 4) {
            return $period ** 2 - 2;
        } else {
            return ($period * 2) - 1;
        }
    }

    public function getDateDiffAmountFilter(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        $interval = $start->diff($end);

        return max(1, (int) $interval->format('%a'));
    }

    // prüft einen float-Wert, ob die Nachkommastellen 0 sind
    /**
     * Missing values keep the existing arithmetic treatment as zero.
     *
     * @param int|float|numeric-string|null $float
     */
    public function isDecimalPlace0(int|float|string|null $float): bool
    {
        // prüfe 1. Nachkommastelle, ob sie 0 ist
        if ((($float * 10) % 10) === 0) {
            // prüfe 2. Nachkommastelle, ob sie 0 ist
            if ((($float * 100) % 10) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param int|numeric-string $monthNumber */
    public function getLocalizedMonthFilter(int|string $monthNumber, string $pattern, ?string $locale): string|false
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);

        return $formatter->format(mktime(0, 0, 0, $monthNumber + 1, 0, 0));
    }

    public function getActiveRouteNameFilter(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || !$request->attributes->has('_route')) {
            $request = $this->requestStack->getMainRequest();
        }
        $route = str_replace('_', '.', $request?->attributes->get('_route', '') ?? '');

        return $route;
    }

    /** Pass the value through unchanged to IntlDateFormatter's open formatting contract. */
    public function getLocalizedDateFilter(mixed $date, string $pattern, ?string $locale): string|false
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::FULL);
        $formatter->setPattern($pattern);

        return $formatter->format($date);
    }

    /**
     * Objects expose getId(); identifiers are compared strictly, regardless of their class.
     *
     * @param iterable<object> $array
     */
    public function existsById(iterable $array, object $compare): bool
    {
        foreach ($array as $single) {
            if ($single->getId() === $compare->getId()) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<Holiday> */
    public function getPublicdaysForDay(\DateTime $date, string $code, string $locale): iterable
    {
        return $this->calendarService->getPublicdaysForDay($date, $code, $locale);
    }

    /**
     * Upper bound on how many colors a single accent row holds before a
     * second (third, ...) row starts - 4 equal segments in a header cell
     * this narrow is already tight, a 5th would be unreadable.
     */
    private const ACCENT_ROW_MAX_COLORS = 4;

    /** Thickness of one accent row, in pixels. */
    private const ACCENT_ROW_HEIGHT_PX = 3;

    /**
     * Builds an accent-bar style for a set of hex colors, one per configured
     * calendar that has an entry on this day. Each row of up to 4 colors is
     * one horizontal bar split into that many equal-width segments (2
     * colors -> 50/50, 3 -> thirds, 4 -> quarters); a 5th color onward
     * starts a second stacked row, sized as evenly as possible across rows
     * rather than maxing out each row before starting the next (5 -> 3+2,
     * not 4+1; 6 -> 3+3, not 4+2) so no single row is ever more lopsided
     * than it has to be.
     *
     * Implemented as stacked background-image layers (one hard-stop
     * linear-gradient per row) rather than the box-shadow-per-color
     * technique this replaced, since box-shadow can only paint whole-width
     * lines - splitting a row into side-by-side segments needs a gradient.
     *
     * @param string[] $colors
     */
    public function getCalendarAccentMarkerStyle(array $colors): string
    {
        $colors = array_values(array_unique($colors));
        if ([] === $colors) {
            return '';
        }

        $rows = $this->splitIntoBalancedRows($colors, self::ACCENT_ROW_MAX_COLORS);

        $images = [];
        $positions = [];
        $sizes = [];
        foreach ($rows as $i => $rowColors) {
            $images[] = $this->hardStopGradient($rowColors);
            $positions[] = sprintf('0 %dpx', $i * self::ACCENT_ROW_HEIGHT_PX);
            $sizes[] = sprintf('100%% %dpx', self::ACCENT_ROW_HEIGHT_PX);
        }

        // These cells are sticky-positioned table headers, which fake their
        // bottom border via an inset box-shadow (see .table-sticky thead th
        // in app.css) rather than a real border - since this inline style
        // replaces the whole box-shadow property, re-add that layer here so
        // entry cells don't lose their bottom border underneath the bars.
        return sprintf(
            ' box-shadow: inset 0 -1px 0 0 var(--bs-border-color); background-image: %s; background-position: %s; background-size: %s; background-repeat: no-repeat;',
            implode(', ', $images),
            implode(', ', $positions),
            implode(', ', $sizes),
        );
    }

    /**
     * Splits $colors into ceil(count($colors) / $maxPerRow) rows, as evenly
     * sized as possible (earlier rows get the remainder, one extra color
     * each) rather than filling every row up to $maxPerRow before starting
     * the next.
     *
     * @param string[] $colors
     *
     * @return list<string[]>
     */
    private function splitIntoBalancedRows(array $colors, int $maxPerRow): array
    {
        $total = \count($colors);
        $rowCount = (int) ceil($total / $maxPerRow);
        $base = intdiv($total, $rowCount);
        $extra = $total % $rowCount;

        $rows = [];
        $offset = 0;
        for ($i = 0; $i < $rowCount; ++$i) {
            $size = $base + ($i < $extra ? 1 : 0);
            $rows[] = \array_slice($colors, $offset, $size);
            $offset += $size;
        }

        return $rows;
    }

    /**
     * A linear-gradient with hard color stops - no actual gradient/blend,
     * just $colors painted as equal-width side-by-side blocks.
     *
     * @param string[] $colors
     */
    private function hardStopGradient(array $colors): string
    {
        $count = \count($colors);
        $stops = [];
        foreach ($colors as $i => $color) {
            $from = (int) round($i / $count * 100);
            $to = (int) round(($i + 1) / $count * 100);
            $stops[] = sprintf('%s %d%%', $color, $from);
            $stops[] = sprintf('%s %d%%', $color, $to);
        }

        return 'linear-gradient(to right, '.implode(', ', $stops).')';
    }

    /**
     * @param array<Reservation> $reservations
     *
     * @return list<Reservation>
     */
    public function getReservationsForDay(\DateTimeInterface $day, array $reservations): array
    {
        $result = [];

        /* @var $reservation Reservation */
        foreach ($reservations as $reservation) {
            $start = new \DateTimeImmutable($reservation->getStartDate()->format('Y-m-d').' UTC');
            // An event ends on its last day, the stored end day after it is free again.
            $end = new \DateTimeImmutable(($reservation->isEventSpace() ? $reservation->getLastDay() : $reservation->getEndDate())->format('Y-m-d').' UTC');
            // todo store all reservation dates as UTC time
            if ($day >= $start && $day <= $end) {
                $result[] = $reservation;
            }
        }

        return $result;
    }

    /**
     * Resolves overlapping reservation conflicts for displaying the reservations
     * Each row of the array returns a list of reservations without conflicts.
     *
     * @param array<Reservation> $reservations
     *
     * @return list<list<Reservation>>
     *
     * @throws \Exception
     */
    public function getReservationsMultipleOccupancy(array $reservations): array
    {
        $result = [[]];
        $rowCount = 0;  // holds the current line for an overlapping reservation
        $removedKeys = [];
        /* @var $reservation Reservation */
        foreach ($reservations as $outerKey => $reservation) {
            // unset has no effect to outer loop and reservations will be still used here therefore we need to skip them
            if (in_array($outerKey, $removedKeys)) {
                continue;
            }

            $start = new \DateTimeImmutable($reservation->getStartDate()->format('Y-m-d').' UTC');
            $end = new \DateTimeImmutable($reservation->getEndDate()->format('Y-m-d').' UTC');
            $lastRowEnd = $end;
            foreach ($reservations as $key => $compare) {
                $start2 = new \DateTimeImmutable($compare->getStartDate()->format('Y-m-d').' UTC');
                $end2 = new \DateTimeImmutable($compare->getEndDate()->format('Y-m-d').' UTC');
                // if reservations overlap put this one to a new line
                if (($start > $start2 && $end <= $end2) || ($start <= $start2 && $end <= $end2 && $end > $start2)) {
                    $result[$rowCount][] = $compare;
                    ++$rowCount;
                    unset($reservations[$key]);
                    $removedKeys[] = $key;
                } elseif ($lastRowEnd <= $start2) {
                    // otherwise check whether the reservation can be added to the same line (after the current one)
                    $resPosition = $this->getPositionOfReservation($result, $reservation);
                    if (false !== $resPosition) {
                        $result[$resPosition][] = $compare;
                        $lastRowEnd = $end2;
                        unset($reservations[$key]);
                        $removedKeys[] = $key;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Finds the position (line) of a given reservation in multidimensional array of reservations.
     *
     * @param array<int, array<Reservation>> $reservationLines
     */
    private function getPositionOfReservation(array $reservationLines, Reservation $reservation): int|bool
    {
        foreach ($reservationLines as $line => $reservations) {
            foreach ($reservations as $res) {
                if ($res->getId() === $reservation->getId()) {
                    return $line;
                }
            }
        }

        return false;
    }

    public function timestamp2UTC(int $timestamp): \DateTime
    {
        $result = new \DateTime();
        $result->setTimestamp($timestamp);
        $result->setTimezone(new \DateTimeZone('UTC'));

        return $result;
    }

    public function date2UTC(\DateTimeInterface $date): \DateTime
    {
        return new \DateTime($date->format('Y-m-d').' UTC');
    }
}
