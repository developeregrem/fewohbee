<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\OnlineBookingConfigService;
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
        PublicBookingAbuseProtectionService $abuseProtectionService
    ): Response
    {
        $config = $configService->getConfig();
        $embed = '1' === (string) $request->query->get('embed', $request->request->get('embed', '0'));
        $error = $publicBookingService->validateEnabledConfig();
        $countries = Countries::getNames($request->getLocale());
        $defaultCountry = $countries['DE'] ?? 'Deutschland';
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
                'country' => (string) $request->request->get('country', $defaultCountry),
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
        $qtyByType = $this->extractQtyByType($request);
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

            if ('availability' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, [], $request);
                $view['availabilityChecked'] = true;
                $view['step'] = 2;
                $view['availability'] = $preview['availability'];
                $view['formState'] = $abuseProtectionService->createFormState(false);
            } elseif ('preview' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, $qtyByType, $request);
                $view['availabilityChecked'] = true;
                $view['step'] = 3;
                $view['availability'] = $preview['availability'];
                $view['selectedQty'] = $preview['selected'];
                $view['roomTotalFormatted'] = $preview['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $preview['roomPriceBreakdown'];
                $view['formState'] = $abuseProtectionService->createFormState(true);
            } elseif ('submit' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, $qtyByType, $request);
                $view['availabilityChecked'] = true;
                $view['step'] = 3;
                $view['availability'] = $preview['availability'];
                $view['selectedQty'] = $preview['selected'];
                $view['roomTotalFormatted'] = $preview['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $preview['roomPriceBreakdown'];
                $view['formState'] = $abuseProtectionService->createFormState(true);

                $result = $publicBookingService->createBooking(
                    $dateFrom,
                    $dateTo,
                    $persons,
                    $roomsCount,
                    $qtyByType,
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
        } catch (\Throwable $e) {
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
                    $fallbackPreview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, [], $request);
                    $view['availabilityChecked'] = true;
                    $view['availability'] = $fallbackPreview['availability'];
                } catch (\Throwable) {
                }
            }

            if ([] !== $view['availability']) {
                $view['step'] = 'submit' === $intent ? 3 : 2;
                $view['formState'] = $abuseProtectionService->createFormState('submit' === $intent);
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
            throw new \RuntimeException('online_booking.error.dates_required');
        }

        try {
            $dateFrom = new \DateTimeImmutable($dateFromRaw);
            $dateTo = new \DateTimeImmutable($dateToRaw);
        } catch (\Throwable) {
            throw new \RuntimeException('online_booking.error.invalid_dates');
        }

        $persons = max(1, (int) $request->request->get('persons', 1));
        $roomsCount = max(1, (int) $request->request->get('roomsCount', 1));
        $minArrivalDate = new \DateTimeImmutable('tomorrow');

        if ($dateFrom >= $dateTo) {
            throw new \RuntimeException('online_booking.error.departure_after_arrival');
        }

        if ($dateFrom < $minArrivalDate) {
            throw new \RuntimeException('online_booking.error.arrival_must_be_future');
        }

        return [$dateFrom, $dateTo, $persons, $roomsCount];
    }

    /**
     * Extract room-type quantity selections from POST fields with the `qty_` prefix.
     *
     * @return array<string, int>
     */
    private function extractQtyByType(Request $request): array
    {
        $qtyByType = [];
        foreach ($request->request->all() as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'qty_')) {
                continue;
            }

            $typeKey = substr($key, 4);
            if ('' === $typeKey) {
                continue;
            }

            $qtyByType[$typeKey] = max(0, (int) $value);
        }

        return $qtyByType;
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
            'country' => (string) $request->request->get('country', $defaultCountry),
            'comment' => (string) $request->request->get('comment', ''),
        ];
    }
}
