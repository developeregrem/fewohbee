<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\PublicBookingException;
use App\Service\OnlineBookingConfigService;
use App\Service\OnlineBookingRestrictionService;
use App\Service\PublicBookingAbuseProtectionService;
use App\Service\PublicBookingService;
use App\Repository\GuestCategoryRepository;
use App\Service\GuestCategoryAgeMapper;
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
        GuestCategoryRepository $guestCategoryRepository,
        GuestCategoryAgeMapper $ageMapper,
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

        // Public-Mode: OTHER-Statistik-Kategorien sind ein Backend-Konzept
        // (Statistik-Berichte) und gehören nicht in die Endkunden-UI.
        $guestCategories = array_values(array_filter(
            $guestCategoryRepository->findActiveOrdered(),
            static fn ($c) => 'other' !== $c->getStatisticalGroup()->value,
        ));

        $view = [
            'embed' => $embed,
            'config' => $config,
            'countries' => $countries,
            'guestCategories' => $guestCategories,
            'errorMessage' => $error,
            'successMessage' => $successMessage,
            'submitFallbackNotice' => false,
            'minArrivalDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'maxDepartureDate' => $restrictionService->getMaxDepartureDate()?->format('Y-m-d'),
            'availabilityChecked' => false,
            'formState' => $abuseProtectionService->createFormState(false),
            'step' => 1,
            'search' => [
                'dateFrom' => (string) $request->request->get('dateFrom', ''),
                'dateTo' => (string) $request->request->get('dateTo', ''),
                'persons' => (int) $request->request->get('persons', 1),
                'roomsCount' => (int) $request->request->get('roomsCount', 1),
                'adults' => max(1, (int) $request->request->get('adults', 1)),
                'childAges' => array_values(array_filter(
                    (array) $request->request->all('childAges'),
                    static fn ($v) => is_numeric($v),
                )),
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
            'extras' => [],
            'selectedExtras' => [],
            'extrasTotalFormatted' => null,
            'grandTotalFormatted' => null,
            'extrasBreakdown' => [],
            'touristTaxLines' => [],
            'touristTaxTotalFormatted' => null,
            'touristTaxTotal' => 0.0,
            'mixOccupancyTotal' => 0,
            'nonOccupancyIcons' => [],
            'bookingResult' => null,
        ];

        if ('POST' !== $request->getMethod() || null !== $error) {
            return $this->render('PublicBooking/book.html.twig', $view);
        }

        $intent = (string) $request->request->get('intent', 'availability');
        $occupancySelection = $this->extractOccupancySelection($request);
        $extrasSelection = $this->extractExtrasSelection($request);
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
            $guestCounts = $this->resolveGuestCounts($request, $ageMapper);
            // Derive persons from the guestCounts when the wizard supplied them
            // — `persons` request field may still carry the legacy fallback.
            // At the same time collect the non-occupancy entries (e.g. infants
            // in a cot) so the wizard can show them as "+ baby"-icons next to
            // the room's bed icons in step 2 — otherwise the guest is unsure
            // whether the room actually accommodates their party.
            if ([] !== $guestCounts) {
                $derived = 0;
                $view['nonOccupancyIcons'] = [];
                foreach ($guestCategoryRepository->findActiveOrdered() as $cat) {
                    $count = (int) ($guestCounts[(int) $cat->getId()] ?? 0);
                    if (0 === $count) {
                        continue;
                    }
                    if ($cat->isCountedInOccupancy()) {
                        $derived += $count;
                    } else {
                        $icon = match ($cat->getStatisticalGroup()->value) {
                            'infant' => 'fa-baby',
                            'child' => 'fa-child',
                            default => 'fa-user-tag',
                        };
                        for ($i = 0; $i < $count; ++$i) {
                            $view['nonOccupancyIcons'][] = ['icon' => $icon, 'label' => $cat->getName()];
                        }
                    }
                }
                if ($derived > 0) {
                    $persons = $derived;
                }
                $view['mixOccupancyTotal'] = $persons;
            }

            $maxDeparture = $restrictionService->getMaxDepartureDate();
            if (null !== $maxDeparture && $dateTo > $maxDeparture) {
                throw new PublicBookingException('online_booking.error.booking_horizon_exceeded');
            }

            if ('availability' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, [], $request, [], $guestCounts);
                $view['availabilityChecked'] = true;
                $view['step'] = 2;
                $view['availability'] = $preview['availability'];
                $view['extras'] = $preview['extras'];
                $view['formState'] = $abuseProtectionService->createFormState(false);
            } elseif ('preview' === $intent) {
                $preview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, $occupancySelection, $request, $extrasSelection, $guestCounts);
                $view['availabilityChecked'] = true;
                $view['step'] = 3;
                $view['availability'] = $preview['availability'];
                $view['selectedQty'] = $preview['selected'];
                $view['extras'] = $preview['extras'];
                $view['selectedExtras'] = $preview['selectedExtras'];
                $view['roomTotalFormatted'] = $preview['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $preview['roomPriceBreakdown'];
                $view['extrasTotalFormatted'] = $preview['extrasTotalFormatted'];
                $view['extrasBreakdown'] = $preview['extrasBreakdown'];
                $view['grandTotalFormatted'] = $preview['grandTotalFormatted'];
                $view['touristTaxLines'] = $preview['touristTaxLines'];
                $view['touristTaxTotalFormatted'] = $preview['touristTaxTotalFormatted'];
                $view['touristTaxTotal'] = $preview['touristTaxTotal'];
                $view['formState'] = $abuseProtectionService->createFormState(true);
            } elseif ('submit' === $intent) {
                $result = $publicBookingService->createBooking(
                    $dateFrom,
                    $dateTo,
                    $persons,
                    $roomsCount,
                    $occupancySelection,
                    $this->extractBookerInput($request, $defaultCountry),
                    $request,
                    $extrasSelection,
                    $guestCounts,
                );

                $view['step'] = 4;
                $view['roomTotalFormatted'] = $result['roomTotalFormatted'];
                $view['roomPriceBreakdown'] = $result['roomPriceBreakdown'];
                $view['bookingResult'] = $result;

                $abuseProtectionService->clearSubmitFailures($request);

                return $this->redirectToRoute('public.booking', [
                    'embed' => $embed ? 1 : 0,
                    'submitted' => 1,
                    'mode' => $config->getBookingMode(),
                ]);
            }
        } catch (PublicBookingException $e) {
            $view['errorMessage'] = $e->getMessage();

            // Repeated failures on the final step are usually something the guest
            // cannot resolve — surface a "contact the property directly" notice.
            if ('submit' === $intent) {
                $view['submitFallbackNotice'] = $abuseProtectionService->registerSubmitFailure($request);
            }

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
                    $selectedExtrasForPreview = 'submit' === $intent ? $extrasSelection : [];
                    $fallbackPreview = $publicBookingService->buildSelectionPreview($dateFrom, $dateTo, $persons, $roomsCount, $selectedForPreview, $request, $selectedExtrasForPreview, $guestCounts ?? []);
                    $view['availabilityChecked'] = true;
                    $view['availability'] = $fallbackPreview['availability'];
                    $view['extras'] = $fallbackPreview['extras'] ?? [];
                    if ('submit' === $intent && isset($fallbackPreview['roomTotalFormatted'])) {
                        $view['roomTotalFormatted'] = $fallbackPreview['roomTotalFormatted'];
                        $view['roomPriceBreakdown'] = $fallbackPreview['roomPriceBreakdown'] ?? [];
                        $view['selectedExtras'] = $extrasSelection;
                        $view['extrasTotalFormatted'] = $fallbackPreview['extrasTotalFormatted'] ?? null;
                        $view['extrasBreakdown'] = $fallbackPreview['extrasBreakdown'] ?? [];
                        $view['grandTotalFormatted'] = $fallbackPreview['grandTotalFormatted'] ?? null;
                    }
                } catch (\Throwable) {
                }
            }

            if ([] !== $view['availability']) {
                $view['step'] = 'submit' === $intent ? 3 : 2;
                $view['formState'] = $abuseProtectionService->createFormState('submit' === $intent);
                if ('submit' === $intent) {
                    $view['selectedQty'] = $occupancySelection;
                    $view['selectedExtras'] = $extrasSelection;
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
        $minArrivalDate = new \DateTimeImmutable('today');

        if ($dateFrom > $dateTo) {
            throw new PublicBookingException('online_booking.error.departure_after_arrival');
        }

        if ($dateFrom < $minArrivalDate) {
            throw new PublicBookingException('online_booking.error.arrival_must_be_future');
        }

        return [$dateFrom, $dateTo, $persons, $roomsCount];
    }

    /**
     * Resolves the wizard's per-category counts into a `{categoryId: count}` map.
     *
     * Preferred input: `adults` (int) + `childAges[]`
     * (array of int ages). The age mapper looks up each child's age against
     * the configured GuestCategory ranges. This keeps the public UI to two
     * inputs even when the hotelier has many child tiers.
     *
     * Fallback input: `guestCounts` JSON keyed directly by category id —
     * used by API clients or non-browser submissions.
     *
     * @return array<int, int>
     */
    private function resolveGuestCounts(Request $request, GuestCategoryAgeMapper $ageMapper): array
    {
        $adultsRaw = $request->request->get('adults');
        $childAgesRaw = $request->request->all('childAges');
        if (null !== $adultsRaw || (is_array($childAgesRaw) && [] !== $childAgesRaw)) {
            $adults = max(0, (int) $adultsRaw);
            $childAges = [];
            if (is_array($childAgesRaw)) {
                foreach ($childAgesRaw as $age) {
                    $age = (int) $age;
                    if ($age >= 0 && $age <= 120) {
                        $childAges[] = $age;
                    }
                }
            }

            return $ageMapper->map($adults, $childAges);
        }

        return $this->parseGuestCounts($request);
    }

    /**
     * Legacy fallback: directly parse a `guestCounts` JSON field
     * (`{categoryId: count}`) from the request.
     *
     * @return array<int, int>
     */
    private function parseGuestCounts(Request $request): array
    {
        $raw = $request->request->get('guestCounts');
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $normalized = [];
        foreach ($decoded as $catId => $count) {
            $catId = (int) $catId;
            $count = (int) $count;
            if ($catId > 0 && $count > 0) {
                $normalized[$catId] = $count;
            }
        }

        return $normalized;
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
     * Extract selected extras with quantities from POST fields.
     *
     * Field format: extra_{priceId} = quantity (1+ means selected)
     *
     * @return array<int, int> Map of Price ID => quantity
     */
    private function extractExtrasSelection(Request $request): array
    {
        $selected = [];
        foreach ($request->request->all() as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'extra_')) {
                continue;
            }
            $priceId = (int) substr($key, 6);
            $qty = max(0, (int) $value);
            if ($priceId > 0 && $qty > 0) {
                $selected[$priceId] = $qty;
            }
        }

        return $selected;
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
