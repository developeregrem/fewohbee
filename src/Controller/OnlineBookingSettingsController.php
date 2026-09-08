<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BookingRestrictionRule;
use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Form\OnlineBookingConfigType;
use App\Repository\BookingRestrictionRuleRepository;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use App\Repository\PriceRepository;
use App\Repository\RoomCategoryRepository;
use App\Repository\WorkflowRepository;
use App\Service\BookingRestrictionPresentation;
use App\Service\BookingRestrictionService;
use App\Service\OnlineBookingConfigService;
use App\Service\PublicBookingCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Maintains online booking presentation and room publication settings. */
#[Route('/settings/online-booking')]
#[IsGranted('ROLE_ADMIN')]
class OnlineBookingSettingsController extends AbstractController
{
    /** Render and persist the system-wide online booking settings. */
    #[Route('', name: 'settings.online_booking.index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        OnlineBookingConfigService $configService,
        RoomCategoryRepository $roomCategoryRepository,
        OnlineBookingRoomCategoryLimitRepository $limitRepository,
        PriceRepository $priceRepository,
        PublicBookingCalendarService $calendarService,
        WorkflowRepository $workflowRepository,
        BookingRestrictionRuleRepository $ruleRepository,
        BookingRestrictionService $restrictions,
        BookingRestrictionPresentation $presentation,
    ): Response {
        $config = $configService->getConfig();
        $form = $this->createForm(OnlineBookingConfigType::class, $config, [
            'attr' => [
                'data-controller' => 'online-booking-settings',
            ],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $configService->saveConfig($config);
            $this->addFlash('success', 'online_booking.flash.settings_saved');

            return $this->redirectToRoute('settings.online_booking.index');
        }

        $categories = $roomCategoryRepository->findAll();
        $limitsByCategory = $limitRepository->findAllIndexedByCategory();
        $origin = $configService->getReservationOrigin($config);
        $priceOverview = null !== $origin
            ? $priceRepository->findActivePricesByOrigin((int) $origin->getId())
            : ['room' => [], 'misc' => [], 'extras' => []];

        // Unlimited rules and special periods are the same entity; the settings page shows
        // them in two lists because that is how an operator thinks about them.
        $allRules = $ruleRepository->findForSettings();
        $descriptions = [];
        foreach ($allRules as $rule) {
            $descriptions[(int) $rule->getId()] = $presentation->describe($rule);
        }

        $periods = array_values(array_filter($allRules, static fn (BookingRestrictionRule $rule): bool => $rule->isPeriod()));

        // Special periods that have all expired silently stop restricting anything, which is
        // easy to miss on a page this long. Only enabled periods count — a disabled one was
        // parked deliberately. End dates are exclusive, so a period is over once its end has
        // arrived.
        $today = new \DateTimeImmutable('today');
        $activePeriods = array_filter($periods, static fn (BookingRestrictionRule $rule): bool => $rule->isEnabled());
        $periodsAllPast = [] !== $activePeriods && array_all(
            $activePeriods,
            static fn (BookingRestrictionRule $rule): bool => null !== $rule->getEndDate() && $rule->getEndDate() <= $today,
        );

        $matrixCategory = $roomCategoryRepository->find($request->query->getInt('matrixCategory')) ?? ($categories[0] ?? null);
        $matrixWeek = BookingRestrictionController::parseWeek($request->query->getString('week'));

        return $this->render('Settings/OnlineBooking/index.html.twig', [
            'form' => $form->createView(),
            'rules' => array_values(array_filter($allRules, static fn (BookingRestrictionRule $rule): bool => !$rule->isPeriod())),
            'periods' => $periods,
            'periodsAllPast' => $periodsAllPast,
            'ruleDescriptions' => $descriptions,
            'matrixCategory' => $matrixCategory,
            'matrixWeek' => $matrixWeek,
            'matrixRows' => null === $matrixCategory ? [] : $restrictions->getWeekMatrix($matrixCategory, $matrixWeek),
            'maxNights' => BookingRestrictionService::MATRIX_NIGHTS,
            'reservationOriginConfigured' => null !== $origin,
            'categories' => $categories,
            'limitsByCategory' => $limitsByCategory,
            'priceOverview' => $priceOverview,
            'bookingConfirmationWorkflow' => $workflowRepository->findBySystemCode('confirm_online_booking'),
            // Lets the theme card explain a calendar that stays empty because every
            // released room uses multiple occupancy.
            'calendarEligibleRoomCount' => $calendarService->countEligibleRooms($config),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Saves online room-count and occupancy limits; minimum stays live in the shared rule editor. */
    #[Route('/restrictions/categories', name: 'settings.online_booking.save_category_restrictions', methods: ['POST'])]
    public function saveCategoryRestrictions(
        Request $request,
        RoomCategoryRepository $roomCategoryRepository,
        OnlineBookingRoomCategoryLimitRepository $limitRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('ob_category_restrictions', $request->request->get('_token'))) {
            $this->addFlash('danger', 'online_booking.flash.invalid_token');

            return $this->redirectToRoute('settings.online_booking.index');
        }

        $categories = $roomCategoryRepository->findAll();
        $limitsByCategory = $limitRepository->findAllIndexedByCategory();

        foreach ($categories as $category) {
            $catId = $category->getId();
            $maxRooms = $this->parseNullableInt($request->request->get('max_rooms_'.$catId));
            $minOccupancy = $this->parseNullableInt($request->request->get('min_occupancy_'.$catId));

            // Room limit + min occupancy
            $limit = $limitsByCategory[$catId] ?? null;
            if (null !== $maxRooms || null !== $minOccupancy) {
                if (null === $limit) {
                    $limit = new OnlineBookingRoomCategoryLimit();
                    $limit->setRoomCategory($category);
                    $em->persist($limit);
                }
                $limit->setMaxRooms($maxRooms);
                $limit->setMinOccupancy($minOccupancy);
            } elseif (null !== $limit) {
                $em->remove($limit);
            }
        }

        $em->flush();
        $this->addFlash('success', 'online_booking.flash.restrictions_saved');

        return $this->redirectToRoute('settings.online_booking.index');
    }

    private function parseNullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value || '' === trim((string) $value)) {
            return null;
        }

        return (int) $value;
    }
}
