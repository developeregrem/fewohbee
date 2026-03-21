<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\PublicBookingException;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicBookingAbuseProtectionService;
use App\Service\PublicBookingService;
use Symfony\Component\Intl\Countries;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ): Response
    {
        $config = $configService->getConfig();
        $embed = '1' === (string) $request->query->get('embed', $request->request->get('embed', '0'));
        $error = $publicBookingService->validateEnabledConfig();
        $countries = Countries::getNames($request->getLocale());
        $defaultCountry = mb_strtoupper($request->getLocale());
        $successMessage = null;

        if ('1' === (string) $request->query->get('submitted')) {
            $successMessage = 'online_booking.flash.request_submitted';
            if ('BOOKING' === (string) $request->query->get('mode', $config->getBookingMode())) {
                $successMessage = 'online_booking.flash.booking_created';
            }
        }

        $view = [
            'embed' => $embed,
            'config' => $config,
            'countries' => $countries,
            'errorMessage' => $error,
            'successMessage' => $successMessage,
            'minArrivalDate' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'maxDepartureDate' => $restrictionService->getMaxDepartureDate()?->format('Y-m-d'),
            'availabilityChecked' => false,
            'formState' => $abuseProtectionService->createFormState(false),
            'step' => 1,
            'search' => [
                'dateFrom' => (string) $request->request->get('dateFrom', ''),
                'dateTo' => (string) $request->request->get('dateTo', ''),
                'persons' => (int) $request->request->get('persons', 1),
                'roomsCount' => (int) $request->request->get('roomsCount', 1),
            ],
            'availability' => [],
            'selectedQty' => [],
            'booker' => [
                'salutation' => (string) $request->request->get('salutation', ''),
                'firstname' => (string) $request->request->get('firstname', ''),
                'lastname' => (string) $request->request->get('lastname', ''),
                'email' => (string) $request->request->get('email', ''),
                'phone' => (string) $request->request->get('phone', ''),
                'company' => (string) $request->request->get('company', ''),
                'address' => (string) $request->request->get('address', ''),
                'zip' => (string) $request->request->get('zip', ''),
                'city' => (string) $request->request->get('city', ''),
                'country' => mb_strtoupper((string) $request->request->get('country', $defaultCountry)),
                'comment' => (string) $request->request->get('comment', ''),
            ],
            'roomTotalFormatted' => null,
            'roomPriceBreakdown' => [],
            'bookingResult' => null,
        ];

        if ('POST' !== $request->getMethod() || null !== $error) {
            return $this->render('PublicBooking/book.html.twig', $view);
        }

        $intent = (string) $request->request->get('intent', 'availability');
        $occupancySelection = $this->extractOccupancySelection($request);
        $dateFrom = null;
        $dateTo = null;
        $persons = null;
        $roomsCount = null;

        try {
            if ('submit' === $intent) {
                $abuseProtectionService->validateSubmitRequest($request);
            } else {
                $abuseProtectionService->validateAvailabilityRequest($request);
            }

            [$dateFrom, $dateTo, $persons, $roomsCount] = $this->parseSearchInput($request);

            $maxDeparture = $restrictionService->getMaxDepartureDate();
            if (null !== $maxDeparture && $dateTo > $maxDeparture) {
                throw new PublicBookingException('online_booking.error.booking_horizon_exceeded');
            }

            if ('availability' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, [], $request);
                $view['availabilityChecked'] = true;
                $view['step'] = 2;
                $view['availability'] = $preview['availability'];
                $view['formState'] = $abuseProtectionService->createFormState(false);
            } elseif ('preview' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, $occupancySelection, $request);
                $view['availabilityChecked'] = true;
                $view['step'] = 3;
                $view['availability'] = $preview['availability'];
                $view['selectedQty'] = $preview['selected'];
                $view['roomTotalFormatted'] = $preview['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $preview['roomPriceBreakdown'];
                $view['formState'] = $abuseProtectionService->createFormState(true);
            } elseif ('submit' === $intent) {
                $result = $publicBookingService->createBooking(
                    $dateFrom,
                    $dateTo,
                    $persons,
                    $roomsCount,
                    $occupancySelection,
                    $this->extractBookerInput($request, $defaultCountry),
                    $request
                );

                $view['step'] = 4;
                $view['roomTotalFormatted'] = $result['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $result['roomPriceBreakdown'];
                $view['bookingResult'] = $result;

                return $this->redirectToRoute('public.booking', [
                    'embed' => $embed ? 1 : 0,
                    'submitted' => 1,
                    'mode' => $config->getBookingMode(),
                ]);
            }
        } catch (PublicBookingException $e) {
            $view['errorMessage'] = $e->getMessage();

            if (
                $dateFrom instanceof \DateTimeImmutable
                && $dateTo instanceof \DateTimeImmutable
                && is_int($persons)
                && is_int($roomsCount)
                && in_array($intent, ['preview', 'submit'], true)
                && [] === $view['availability']
            ) {
                try {
                    $selectedForPreview = 'submit' === $intent ? $occupancySelection : [];
                    $fallbackPreview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, $selectedForPreview, $request);
                    $view['availabilityChecked'] = true;
                    $view['availability'] = $fallbackPreview['availability'];
                    if ('submit' === $intent && isset($fallbackPreview['roomTotalFormatted'])) {
                        $view['roomTotalFormatted'] = $fallbackPreview['roomTotalFormatted'];
                        $view['roomPriceBreakdown'] = $fallbackPreview['roomPriceBreakdown'] ?? [];
                    }
                } catch (\Throwable) {
                }
            }

            if ([] !== $view['availability']) {
                $view['step'] = 'submit' === $intent ? 3 : 2;
                $view['formState'] = $abuseProtectionService->createFormState('submit' === $intent);
                if ('submit' === $intent) {
                    $view['selectedQty'] = $occupancySelection;
                }
            } elseif ('' !== (string) $request->request->get('dateFrom') && '' !== (string) $request->request->get('dateTo')) {
                $view['step'] = 2;
                $view['formState'] = $abuseProtectionService->createFormState(false);
            }
        }

        return $this->render('PublicBooking/book.html.twig', $view);
    }

    /**
     * Parse and validate basic search inputs used in all public booking steps.
     *
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable,2:int,3:int}
     */
    private function parseSearchInput(Request $request): array
    {
        $dateFromRaw = (string) $request->request->get('dateFrom', '');
        $dateToRaw = (string) $request->request->get('dateTo', '');
        if ('' === $dateFromRaw || '' === $dateToRaw) {
            throw new PublicBookingException('online_booking.error.dates_required');
        }

        try {
            $dateFrom = new \DateTimeImmutable($dateFromRaw);
            $dateTo = new \DateTimeImmutable($dateToRaw);
        } catch (\Throwable) {
            throw new PublicBookingException('online_booking.error.invalid_dates');
        }

        $persons = max(1, (int) $request->request->get('persons', 1));
        $roomsCount = max(1, (int) $request->request->get('roomsCount', 1));
        $minArrivalDate = new \DateTimeImmutable('tomorrow');

        if ($dateFrom > $dateTo) {
            throw new PublicBookingException('online_booking.error.departure_after_arrival');
        }

        if ($dateFrom < $minArrivalDate) {
            throw new PublicBookingException('online_booking.error.arrival_must_be_future');
        }

        return [$dateFrom, $dateTo, $persons, $roomsCount];
    }

    /**
     * Extract occupancy-based selection from POST fields.
     *
     * Field format: occ_{typeKey}_p{persons} = quantity
     * Example: occ_category:1_p2 = 1 means "1 room of category:1 with 2 persons"
     *
     * @return array<string, array<int, int>> e.g. ['category:1' => [2 => 1]]
     */
    private function extractOccupancySelection(Request $request): array
    {
        $selection = [];
        foreach ($request->request->all() as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'occ_')) {
                continue;
            }

            $remainder = substr($key, 4);
            $lastUnderscore = strrpos($remainder, '_p');
            if (false === $lastUnderscore) {
                continue;
            }

            $typeKey = substr($remainder, 0, $lastUnderscore);
            $personsStr = substr($remainder, $lastUnderscore + 2);
            if ('' === $typeKey || '' === $personsStr) {
                continue;
            }

            $persons = max(0, (int) $personsStr);
            $qty = max(0, (int) $value);
            if ($persons > 0) {
                $selection[$typeKey][$persons] = $qty;
            }
        }

        return $selection;
    }

    /**
     * Extract the public booker/contact payload for reservation creation.
     *
     * @return array<string, string>
     */
    private function extractBookerInput(Request $request, string $defaultCountry): array
    {
        return [
            'salutation' => (string) $request->request->get('salutation', ''),
            'firstname' => (string) $request->request->get('firstname', ''),
            'lastname' => (string) $request->request->get('lastname', ''),
            'email' => (string) $request->request->get('email', ''),
            'phone' => (string) $request->request->get('phone', ''),
            'company' => (string) $request->request->get('company', ''),
            'address' => (string) $request->request->get('address', ''),
            'zip' => (string) $request->request->get('zip', ''),
            'city' => (string) $request->request->get('city', ''),
            'country' => mb_strtoupper((string) $request->request->get('country', $defaultCountry)),
            'comment' => (string) $request->request->get('comment', ''),
        ];
    }
}
