<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\PublicBookingTheme;
use App\Entity\OnlineBookingConfig;
use App\Exception\PublicBookingException;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicBookingAbuseProtectionService;
use App\Service\PublicBookingCalendarService;
use App\Service\PublicBookingRequestMapper;
use App\Service\PublicBookingService;
use App\Service\PublicBookingViewModelFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicBookingController extends AbstractController
{
    /** Render the public booking page and process the multi-step POST flow on the same endpoint. */
    #[Route('/book', name: 'public.booking', methods: ['GET', 'POST'])]
    public function book(
        Request $request,
        PublicBookingService $publicBookingService,
        OnlineBookingConfigService $configService,
        PublicBookingAbuseProtectionService $abuseProtectionService,
        OnlineBookingRestrictionService $restrictionService,
        PublicBookingRequestMapper $requestMapper,
        PublicBookingViewModelFactory $viewFactory,
        PublicBookingCalendarService $calendarService,
    ): Response
    {
        $config = $configService->getConfig();
        $template = $this->resolveTemplate($config, $request);
        // In calendar mode the guest has already chosen the accommodation, so the
        // room travels with every step. Resolving it through the service enforces
        // the release scope — the request identifier is never trusted directly.
        $calendarRoom = $config->isCalendarActive()
            ? $calendarService->findBookableRoom((string) $request->request->get('room', ''), $config)
            : null;
        $embed = '1' === (string) $request->query->get('embed', $request->request->get('embed', '0'));
        $error = $publicBookingService->validateEnabledConfig();
        $defaultCountry = mb_strtoupper($request->getLocale());
        $view = $viewFactory->createInitialView($request, $config, $calendarRoom, $embed, $error);

        if ('POST' !== $request->getMethod() || null !== $error) {
            return $this->render($template, $view);
        }

        $intent = PublicBookingRequestMapper::readIntent($request);
        // Stays null while the input is unusable — the error path below reads that
        // as "nothing to recover from" instead of re-checking every single field.
        $booking = null;

        try {
            if ('submit' === $intent) {
                $abuseProtectionService->validateSubmitRequest($request);
            } else {
                $abuseProtectionService->validateAvailabilityRequest($request);
            }

            $booking = $requestMapper->map($request, $calendarRoom, $defaultCountry);
            // Non-occupancy guests (e.g. an infant in a cot) get their own icons next
            // to the room's bed icons in step 2 — otherwise the guest is unsure
            // whether the room actually accommodates their party.
            $view['nonOccupancyIcons'] = $viewFactory->buildNonOccupancyIcons($booking->guestCounts);
            // The effective occupancy — what the guest is actually booked for. The
            // calendar path prices exactly this number, so it must be set whether or
            // not guest categories are configured.
            $view['mixOccupancyTotal'] = $booking->persons;

            $maxDeparture = $restrictionService->getMaxDepartureDate();
            if (null !== $maxDeparture && $booking->dateTo > $maxDeparture) {
                throw new PublicBookingException('online_booking.error.booking_horizon_exceeded');
            }

            if ('availability' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($booking->dateFrom, $booking->dateTo, $booking->persons, $booking->roomsCount, [], [], $booking->guestCounts, $calendarRoom);
                $view = $viewFactory->applyPreviewResult($view, $preview, 2);
            } elseif ('preview' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($booking->dateFrom, $booking->dateTo, $booking->persons, $booking->roomsCount, $booking->occupancySelection, $booking->extrasSelection, $booking->guestCounts, $calendarRoom);
                $view = $viewFactory->applyPreviewResult($view, $preview, 3);
            } elseif ('submit' === $intent) {
                $result = $publicBookingService->createBooking(
                    $booking->dateFrom,
                    $booking->dateTo,
                    $booking->persons,
                    $booking->roomsCount,
                    $booking->occupancySelection,
                    $booking->booker->toArray(),
                    $booking->extrasSelection,
                    $booking->guestCounts,
                    $calendarRoom,
                );

                $view['step'] = 4;
                $view['roomTotalFormatted'] = $result['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $result['roomPriceBreakdown'];
                $view['modifierBreakdown'] = $result['modifierBreakdown'];
                $view['bookingResult'] = $result;

                $abuseProtectionService->clearSubmitFailures($request);

                return $this->redirectToRoute('public.booking', [
                    'embed' => $embed ? 1 : 0,
                    'submitted' => 1,
                    'mode' => $config->getBookingMode(),
                ]);
            }
        } catch (PublicBookingException $e) {
            $view = $viewFactory->applyErrorState($view, $e, $request, $intent, $booking, $calendarRoom);
        }

        return $this->render($template, $view);
    }

    /**
     * Per-night availability of a single released room, for the booking calendar.
     *
     * Public on purpose, so it lives under /book where the firewall rule and the
     * embedding CSP already apply. Inside the navigable window, everything unusable
     * — calendar switched off, a room the hotelier did not release, a malformed
     * window — answers 404, so the endpoint never confirms what exists. A request at
     * or beyond the booking horizon instead returns the same empty successful page
     * for every valid UUID: this is the regular end of calendar pagination, not a
     * missing resource. The reply carries per-night booleans and the pagination
     * state only.
     */
    #[Route('/book/calendar-data', name: 'public.booking.calendar_data', methods: ['GET'])]
    public function calendarData(
        Request $request,
        PublicBookingCalendarService $calendarService,
        PublicBookingAbuseProtectionService $abuseProtectionService,
    ): JsonResponse {
        try {
            $abuseProtectionService->validateCalendarRequest($request);
        } catch (PublicBookingException) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $availability = $calendarService->getAvailability(
            (string) $request->query->get('room', ''),
            (string) $request->query->get('from', ''),
            (int) $request->query->get('months', 2),
        );

        if (null === $availability) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse($availability->toArray());
        // Availability is already conservative; a short private cache keeps month
        // paging responsive without letting the data go stale for long.
        $response->setPrivate();
        $response->setMaxAge(60);

        return $response;
    }

    /**
     * Resolve the booking page template for the configured theme.
     *
     * Administrators may preview the other theme with `?previewTheme=`. The parameter is
     * ignored for everyone else, and the enum keeps the resulting path a fixed whitelist.
     */
    private function resolveTemplate(OnlineBookingConfig $config, Request $request): string
    {
        $theme = $config->getTheme();

        $preview = (string) $request->query->get('previewTheme', '');
        if ('' !== $preview && $this->isGranted('ROLE_ADMIN')) {
            $theme = PublicBookingTheme::tryFrom($preview) ?? $theme;
        }

        return $theme->bookTemplate();
    }
}
