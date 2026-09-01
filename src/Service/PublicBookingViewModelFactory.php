<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PublicBooking\PublicBookingRequest;
use App\Entity\Appartment;
use App\Entity\OnlineBookingConfig;
use App\Exception\PublicBookingException;
use App\Repository\GuestCategoryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Intl\Countries;

/**
 * Assembles the template variables for the public booking wizard.
 *
 * Both themes consume the exact same set of variables, so this is the single place
 * that decides what the booking page can show. Keeping it out of the controller also
 * keeps the error path honest: a failed step still has to render a usable page, and
 * that recovery logic lives here next to the happy path it mirrors.
 */
class PublicBookingViewModelFactory
{
    public function __construct(
        private readonly OnlineBookingConfigService $configService,
        private readonly OnlineBookingRestrictionService $restrictionService,
        private readonly PublicBookingAbuseProtectionService $abuseProtectionService,
        private readonly PublicBookingCalendarService $calendarService,
        private readonly PublicBookingService $publicBookingService,
        private readonly PublicBookingRequestMapper $requestMapper,
        private readonly GuestCategoryRepository $guestCategoryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The view as it looks before any step has been processed — also the final view
     * for a plain GET and for a configuration that is switched off.
     *
     * @param Appartment|null $calendarRoom the room picked in the calendar, already scope-checked
     *
     * @return array<string, mixed>
     */
    public function createInitialView(
        Request $request,
        OnlineBookingConfig $config,
        ?Appartment $calendarRoom,
        bool $embed,
        ?string $errorMessage,
    ): array {
        return [
            'embed' => $embed,
            'config' => $config,
            'countries' => Countries::getNames($request->getLocale()),
            'guestCategories' => $this->publicGuestCategories(),
            'errorMessage' => $errorMessage,
            'successMessage' => $this->resolveSuccessMessage($request, $config),
            'submitFallbackNotice' => false,
            'minArrivalDate' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'maxDepartureDate' => $this->restrictionService->getMaxDepartureDate()?->format('Y-m-d'),
            'availabilityChecked' => false,
            'formState' => $this->abuseProtectionService->createFormState(false),
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
            // Same extraction the submit path uses, so a redisplayed form always
            // shows exactly what the guest typed.
            'booker' => $this->requestMapper->mapBooker($request, mb_strtoupper($request->getLocale()))->toArray(),
            'roomTotalFormatted' => null,
            'roomPriceBreakdown' => [],
            'modifierBreakdown' => [],
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
            'calendarActive' => $config->isCalendarActive(),
            'selectedRoomUuid' => null !== $calendarRoom ? (string) $calendarRoom->getUuid() : '',
            'selectedRoomCapacity' => null !== $calendarRoom ? (int) $calendarRoom->getBedsMax() : 0,
            'calendarRooms' => $this->calendarService->getSelectableRooms($config),
            'calendarHorizonMonths' => $this->monthsUntil($this->calendarService->getHorizonEnd()),
            'bookableRoomCount' => $this->configService->countBookableRooms($config),
        ];
    }

    /**
     * Icons for guests that do not occupy a bed — one per guest, so the wizard can
     * show e.g. "+ baby" next to the room's bed icons. Without it the guest cannot
     * tell whether the room actually accommodates the whole party.
     *
     * @param array<int, int> $guestCounts guest category id => count
     *
     * @return array<int, array{icon: string, label: string}>
     */
    public function buildNonOccupancyIcons(array $guestCounts): array
    {
        if ([] === $guestCounts) {
            return [];
        }

        $icons = [];
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            $count = (int) ($guestCounts[(int) $category->getId()] ?? 0);
            if (0 === $count || $category->isCountedInOccupancy()) {
                continue;
            }

            $icon = $category->getStatisticalGroup()->publicIcon();
            for ($i = 0; $i < $count; ++$i) {
                $icons[] = ['icon' => $icon, 'label' => (string) $category->getName()];
            }
        }

        return $icons;
    }

    /**
     * Fold a successful availability/preview query into the view.
     *
     * Step 2 only lists what is available; step 3 additionally carries the guest's
     * selection and the priced totals.
     *
     * @param array<string, mixed> $view
     * @param array<string, mixed> $preview result of {@see PublicBookingService::buildSelectionPreview()}
     *
     * @return array<string, mixed>
     */
    public function applyPreviewResult(array $view, array $preview, int $step): array
    {
        $view['availabilityChecked'] = true;
        $view['step'] = $step;
        $view['availability'] = $preview['availability'];
        $view['extras'] = $preview['extras'];
        $view['formState'] = $this->abuseProtectionService->createFormState(3 === $step);

        if (3 !== $step) {
            return $view;
        }

        $view['selectedQty'] = $preview['selected'];
        $view['selectedExtras'] = $preview['selectedExtras'];
        $view['roomTotalFormatted'] = $preview['roomTotalFormatted'];
        $view['roomPriceBreakdown'] = $preview['roomPriceBreakdown'];
        $view['modifierBreakdown'] = $preview['modifierBreakdown'];
        $view['extrasTotalFormatted'] = $preview['extrasTotalFormatted'];
        $view['extrasBreakdown'] = $preview['extrasBreakdown'];
        $view['grandTotalFormatted'] = $preview['grandTotalFormatted'];
        $view['touristTaxLines'] = $preview['touristTaxLines'];
        $view['touristTaxTotalFormatted'] = $preview['touristTaxTotalFormatted'];
        $view['touristTaxTotal'] = $preview['touristTaxTotal'];

        return $view;
    }

    /**
     * Turn a failed step into a page the guest can still act on.
     *
     * Rather than dropping back to an empty search mask, the availability is
     * re-queried so the guest keeps their context and can correct the one thing that
     * went wrong. A booking request that never parsed has nothing to recover from.
     *
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    public function applyErrorState(
        array $view,
        PublicBookingException $error,
        Request $request,
        string $intent,
        ?PublicBookingRequest $booking,
        ?Appartment $calendarRoom,
    ): array {
        $view['errorMessage'] = $error->getMessage();

        // Repeated failures on the final step are usually something the guest
        // cannot resolve — surface a "contact the property directly" notice.
        if ('submit' === $intent) {
            $view['submitFallbackNotice'] = $this->abuseProtectionService->registerSubmitFailure($request);
        }

        if (null !== $booking && in_array($intent, ['preview', 'submit'], true) && [] === $view['availability']) {
            $view = array_merge($view, $this->recoverAvailability($booking, $intent, $calendarRoom));
        }

        if ([] !== $view['availability']) {
            $view['step'] = 'submit' === $intent ? 3 : 2;
            $view['formState'] = $this->abuseProtectionService->createFormState('submit' === $intent);
            if ('submit' === $intent && null !== $booking) {
                $view['selectedQty'] = $booking->occupancySelection;
                $view['selectedExtras'] = $booking->extrasSelection;
            }
        } elseif ('' !== (string) $request->request->get('dateFrom') && '' !== (string) $request->request->get('dateTo')) {
            $view['step'] = 2;
            $view['formState'] = $this->abuseProtectionService->createFormState(false);
        }

        return $view;
    }

    /** Whole months containing bookable nights, counted from the current month. */
    public function monthsUntil(\DateTimeImmutable $end): int
    {
        $firstOfThisMonth = (new \DateTimeImmutable('today'))->modify('first day of this month');
        // The horizon is an exclusive departure boundary. If it falls on the first
        // day of a month, that month contains no bookable night and must not become
        // an otherwise empty calendar page.
        $lastBookableMonth = $end->modify('-1 day')->modify('first day of this month');
        $diff = $firstOfThisMonth->diff($lastBookableMonth);

        return max(1, $diff->y * 12 + $diff->m + 1);
    }

    /**
     * Re-run the preview behind the failed step.
     *
     * @return array<string, mixed> view overrides; empty when the retry fails as well
     */
    private function recoverAvailability(PublicBookingRequest $booking, string $intent, ?Appartment $calendarRoom): array
    {
        $isSubmit = 'submit' === $intent;

        try {
            $preview = $this->publicBookingService->buildSelectionPreview(
                $booking->dateFrom,
                $booking->dateTo,
                $booking->persons,
                $booking->roomsCount,
                $isSubmit ? $booking->occupancySelection : [],
                $isSubmit ? $booking->extrasSelection : [],
                $booking->guestCounts,
                $calendarRoom,
            );
        } catch (\Throwable $e) {
            // Best effort only — the guest already has the original error message,
            // so a failed retry must never replace it with a blank page.
            $this->logger->warning('Public booking error recovery failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
                'intent' => $intent,
            ]);

            return [];
        }

        $recovered = [
            'availabilityChecked' => true,
            'availability' => $preview['availability'],
            'extras' => $preview['extras'],
        ];

        if ($isSubmit) {
            $recovered['roomTotalFormatted'] = $preview['roomTotalFormatted'];
            $recovered['roomPriceBreakdown'] = $preview['roomPriceBreakdown'];
            $recovered['modifierBreakdown'] = $preview['modifierBreakdown'];
            $recovered['selectedExtras'] = $booking->extrasSelection;
            $recovered['extrasTotalFormatted'] = $preview['extrasTotalFormatted'];
            $recovered['extrasBreakdown'] = $preview['extrasBreakdown'];
            $recovered['grandTotalFormatted'] = $preview['grandTotalFormatted'];
        }

        return $recovered;
    }

    /**
     * OTHER statistical categories are a back-office concept (statistics reports)
     * and have no place in the guest-facing wizard.
     *
     * @return array<int, \App\Entity\GuestCategory>
     */
    private function publicGuestCategories(): array
    {
        return array_values(array_filter(
            $this->guestCategoryRepository->findActiveOrdered(),
            static fn ($category) => 'other' !== $category->getStatisticalGroup()->value,
        ));
    }

    /** Flash message shown after the post-submit redirect. */
    private function resolveSuccessMessage(Request $request, OnlineBookingConfig $config): ?string
    {
        if ('1' !== (string) $request->query->get('submitted')) {
            return null;
        }

        if ('BOOKING' === (string) $request->query->get('mode', $config->getBookingMode())) {
            return 'online_booking.flash.booking_created';
        }

        return 'online_booking.flash.request_submitted';
    }
}
