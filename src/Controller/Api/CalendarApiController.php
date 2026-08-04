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

namespace App\Controller\Api;

use App\Entity\Calendar;
use App\Repository\CalendarEntryRepository;
use App\Repository\CalendarRepository;
use App\Service\CalendarService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Yasumi\Yasumi;

/**
 * Token-authenticated read access to public holidays and the user-defined
 * calendars (Calendar/CalendarEntry). Holidays are computed via Yasumi; the
 * caller chooses country and optionally a subdivision (state).
 */
#[Route('/api/v1')]
#[IsGranted('API_SCOPE_CALENDAR_READ')]
class CalendarApiController extends AbstractController
{
    private const MAX_RANGE_DAYS = 731; // two years

    public function __construct(
        private readonly CalendarService $calendarService,
        private readonly CalendarRepository $calendarRepository,
        private readonly CalendarEntryRepository $calendarEntryRepository,
    ) {
    }

    /**
     * Public holidays within a date range for a country / subdivision.
     */
    #[Route('/calendar/holidays', name: 'api.calendar.holidays', methods: ['GET'])]
    public function holidays(Request $request): JsonResponse
    {
        [$start, $end] = $this->parseDateRange($request);

        $country = strtoupper((string) $request->query->get('country', 'DE'));
        $subdivision = $request->query->get('subdivision');
        $subdivision = null !== $subdivision && '' !== $subdivision ? strtoupper($subdivision) : null;
        $locale = (string) $request->query->get('locale', $request->getLocale());

        $providers = Yasumi::getProviders();
        if (2 !== \strlen($country) || !isset($providers[$country])) {
            throw new BadRequestHttpException("Unknown 'country': expected an ISO 3166-1 code supported for holidays (e.g. DE).");
        }
        if (null !== $subdivision) {
            if (!str_starts_with($subdivision, $country.'-') || !isset($providers[$subdivision])) {
                throw new BadRequestHttpException("Unknown 'subdivision': expected an ISO 3166-2 code belonging to the country (e.g. DE-SN).");
            }
        }

        // A subdivision narrows the holiday set; without one the whole
        // country's holidays are used (same rule as the reservation overview).
        $code = $subdivision ?? $country;

        $data = [];
        $day = \DateTime::createFromImmutable($start);
        $endDay = \DateTime::createFromImmutable($end);
        while ($day <= $endDay) {
            foreach ($this->calendarService->getPublicdaysForDay($day, $code, $locale) as $holiday) {
                $data[] = [
                    'date' => $day->format('Y-m-d'),
                    'name' => $holiday->getName(),
                ];
            }
            $day->modify('+1 day');
        }

        return $this->envelope($data, [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'country' => $country,
            'subdivision' => $subdivision,
            'locale' => $locale,
        ]);
    }

    /**
     * List the user-defined calendars.
     */
    #[Route('/calendars', name: 'api.calendars.list', methods: ['GET'])]
    public function calendars(): JsonResponse
    {
        $data = [];
        foreach ($this->calendarRepository->findBy([], ['name' => 'ASC']) as $calendar) {
            $data[] = [
                'id' => $calendar->getId(),
                'name' => $calendar->getName(),
                'color' => $calendar->getColor(),
                'requiresConfirmation' => $calendar->isRequiresConfirmation(),
                'hasIcsSource' => $calendar->hasIcsSource(),
                'lastSyncedAt' => $calendar->getLastSyncedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->envelope($data, []);
    }

    /**
     * Entries of one calendar within a date range.
     */
    #[Route('/calendars/{id}/entries', name: 'api.calendars.entries', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entries(int $id, Request $request): JsonResponse
    {
        $calendar = $this->calendarRepository->find($id);
        if (!$calendar instanceof Calendar) {
            throw new NotFoundHttpException();
        }

        [$start, $end] = $this->parseDateRange($request);

        $data = [];
        foreach ($this->calendarEntryRepository->findForCalendarAndPeriod($calendar, $start, $end) as $entry) {
            $data[] = [
                'id' => $entry->getId(),
                'date' => $entry->getDate()->format('Y-m-d'),
                'title' => $entry->getTitle(),
                'isManuallyCreated' => $entry->isManuallyCreated(),
                'isConfirmed' => $entry->isConfirmed(),
            ];
        }

        return $this->envelope($data, [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'calendar' => ['id' => $calendar->getId(), 'name' => $calendar->getName()],
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start;

        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->format('%a') > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range must not exceed %d days.', self::MAX_RANGE_DAYS));
        }

        return [$start, $end];
    }

    private function parseDate(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m-d.", $paramName));
        }

        return $parsed->setTime(0, 0);
    }

    private function envelope(array $data, array $meta): JsonResponse
    {
        return new JsonResponse([
            'data' => $data,
            'meta' => $meta + ['count' => \count($data)],
        ]);
    }
}
