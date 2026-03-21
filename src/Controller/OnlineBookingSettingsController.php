<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OnlineBookingMinStay;
use App\Entity\OnlineBookingMinStayOverride;
use App\Entity\OnlineBookingRoomCategoryLimit;
use App\Form\OnlineBookingConfigType;
use App\Repository\OnlineBookingMinStayOverrideRepository;
use App\Repository\OnlineBookingMinStayRepository;
use App\Repository\OnlineBookingRoomCategoryLimitRepository;
use App\Repository\RoomCategoryRepository;
use App\Service\OnlineBookingConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        OnlineBookingMinStayRepository $minStayRepository,
        OnlineBookingRoomCategoryLimitRepository $limitRepository,
        OnlineBookingMinStayOverrideRepository $overrideRepository,
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
        $minStayByCategory = $minStayRepository->findAllIndexedByCategory();
        $limitsByCategory = $limitRepository->findAllIndexedByCategory();
        $overrides = $overrideRepository->findBy([], ['startDate' => 'ASC']);

        return $this->render('Settings/OnlineBooking/index.html.twig', [
            'form' => $form->createView(),
            'reservationOriginConfigured' => null !== $configService->getReservationOrigin($config),
            'categories' => $categories,
            'minStayByCategory' => $minStayByCategory,
            'limitsByCategory' => $limitsByCategory,
            'overrides' => $overrides,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Save category restrictions (min stay + max rooms) for all categories at once. */
    #[Route('/restrictions/categories', name: 'settings.online_booking.save_category_restrictions', methods: ['POST'])]
    public function saveCategoryRestrictions(
        Request $request,
        RoomCategoryRepository $roomCategoryRepository,
        OnlineBookingMinStayRepository $minStayRepository,
        OnlineBookingRoomCategoryLimitRepository $limitRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('ob_category_restrictions', $request->request->get('_token'))) {
            $this->addFlash('danger', 'online_booking.flash.invalid_token');

            return $this->redirectToRoute('settings.online_booking.index');
        }

        $categories = $roomCategoryRepository->findAll();
        $minStayByCategory = $minStayRepository->findAllIndexedByCategory();
        $limitsByCategory = $limitRepository->findAllIndexedByCategory();

        foreach ($categories as $category) {
            $catId = $category->getId();
            $weekday = $this->parseNullableInt($request->request->get('min_weekday_'.$catId));
            $weekend = $this->parseNullableInt($request->request->get('min_weekend_'.$catId));
            $maxRooms = $this->parseNullableInt($request->request->get('max_rooms_'.$catId));
            $minOccupancy = $this->parseNullableInt($request->request->get('min_occupancy_'.$catId));

            // Min stay
            $minStay = $minStayByCategory[$catId] ?? null;
            if (null !== $weekday || null !== $weekend) {
                if (null === $minStay) {
                    $minStay = new OnlineBookingMinStay();
                    $minStay->setRoomCategory($category);
                    $em->persist($minStay);
                }
                $minStay->setMinNightsWeekday($weekday);
                $minStay->setMinNightsWeekend($weekend);
            } elseif (null !== $minStay) {
                $em->remove($minStay);
            }

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

    /** Create or update a min-stay override (special period). */
    #[Route('/restrictions/override', name: 'settings.online_booking.save_override', methods: ['POST'])]
    public function saveOverride(
        Request $request,
        RoomCategoryRepository $roomCategoryRepository,
        OnlineBookingMinStayOverrideRepository $overrideRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('ob_override', $request->request->get('_token'))) {
            $this->addFlash('danger', 'online_booking.flash.invalid_token');

            return $this->redirectToRoute('settings.online_booking.index');
        }

        $overrideId = $this->parseNullableInt($request->request->get('override_id'));

        $startDate = $request->request->get('start_date');
        $endDate = $request->request->get('end_date');
        $minNights = (int) $request->request->get('min_nights', 1);

        if (empty($startDate) || empty($endDate) || $minNights < 1) {
            $this->addFlash('danger', 'online_booking.flash.override_invalid');

            return $this->redirectToRoute('settings.online_booking.index');
        }

        $categoryIds = $request->request->all('room_category_ids');

        // Editing an existing override → update single entity
        if (null !== $overrideId) {
            $override = $overrideRepository->find($overrideId);
            if (null !== $override) {
                // When editing, use the first selected category (or null for "all")
                $catId = $this->resolveFirstCategoryId($categoryIds);
                $category = null !== $catId ? $roomCategoryRepository->find($catId) : null;
                $override->setRoomCategory($category);
                $override->setStartDate(new \DateTime($startDate));
                $override->setEndDate(new \DateTime($endDate));
                $override->setMinNights($minNights);
            }
        } else {
            // Creating new → one override per selected category
            $resolvedCategoryIds = $this->resolveSelectedCategoryIds($categoryIds);
            foreach ($resolvedCategoryIds as $catId) {
                $override = new OnlineBookingMinStayOverride();
                $category = null !== $catId ? $roomCategoryRepository->find($catId) : null;
                $override->setRoomCategory($category);
                $override->setStartDate(new \DateTime($startDate));
                $override->setEndDate(new \DateTime($endDate));
                $override->setMinNights($minNights);
                $em->persist($override);
            }
        }

        $em->flush();
        $this->addFlash('success', 'online_booking.flash.override_saved');

        return $this->redirectToRoute('settings.online_booking.index');
    }

    /** Delete a min-stay override. */
    #[Route('/restrictions/override/{id}/delete', name: 'settings.online_booking.delete_override', methods: ['DELETE'])]
    public function deleteOverride(
        Request $request,
        int $id,
        OnlineBookingMinStayOverrideRepository $overrideRepository,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('delete'.$id, $request->request->get('_token'))) {
            $this->addFlash('danger', 'online_booking.flash.invalid_token');

            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $override = $overrideRepository->find($id);
        if (null !== $override) {
            $em->remove($override);
            $em->flush();
            $this->addFlash('success', 'online_booking.flash.override_deleted');
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** Return horizon warning data as JSON for async checks. */
    #[Route('/restrictions/horizon-check', name: 'settings.online_booking.horizon_check', methods: ['GET'])]
    public function horizonCheck(
        OnlineBookingConfigService $configService,
        OnlineBookingMinStayRepository $minStayRepository,
        OnlineBookingMinStayOverrideRepository $overrideRepository,
    ): JsonResponse {
        $config = $configService->getConfig();
        $months = $config->getBookingHorizonMonths();

        if (null === $months || $months < 1) {
            return $this->json(['warning' => false]);
        }

        $horizonEnd = (new \DateTimeImmutable('today'))->modify(sprintf('+%d months', $months));
        $minStayEntries = $minStayRepository->findAll();
        $overrides = $overrideRepository->findAll();

        $hasAnyRestriction = [] !== $minStayEntries || [] !== $overrides;

        if (!$hasAnyRestriction) {
            return $this->json([
                'warning' => true,
                'horizonEnd' => $horizonEnd->format('Y-m-d'),
                'message' => 'no_restrictions_configured',
            ]);
        }

        return $this->json(['warning' => false]);
    }

    /**
     * Resolve category IDs from the multi-select form.
     * Returns [null] when "All categories" is checked (value='').
     *
     * @return list<int|null>
     */
    private function resolveSelectedCategoryIds(array $categoryIds): array
    {
        $ids = [];
        foreach ($categoryIds as $id) {
            if ('' === $id || null === $id) {
                return [null]; // "All categories"
            }
            $ids[] = (int) $id;
        }

        return [] === $ids ? [null] : $ids;
    }

    /**
     * When editing a single override, pick the first selected category ID (or null for "all").
     */
    private function resolveFirstCategoryId(array $categoryIds): ?int
    {
        foreach ($categoryIds as $id) {
            if ('' === $id || null === $id) {
                return null;
            }

            return (int) $id;
        }

        return null;
    }

    private function parseNullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value || '' === trim((string) $value)) {
            return null;
        }

        return (int) $value;
    }
}
