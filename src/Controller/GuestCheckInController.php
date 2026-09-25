<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\GuestCheckIn\GuestCheckInApplyRequest;
use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\Reservation;
use App\Repository\GuestCheckInRepository;
use App\Service\GuestCheckIn\GuestCheckInApplyService;
use App\Service\GuestCheckIn\GuestCheckInLinkService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Staff side of the online check-in, from the reservation dialog: hand out or renew the link and
 * review, take over or discard what the guest sent. The link itself is only handed out on
 * request, so read-only users never find it in the page.
 */
#[Route('/reservation/{id}/checkin', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_RESERVATIONS')]
final class GuestCheckInController extends AbstractController
{
    public function __construct(
        private readonly GuestCheckInLinkService $linkService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/link', name: 'reservations.guest_checkin.link', methods: ['POST'])]
    public function link(Request $request, Reservation $reservation): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::tokenId($reservation), $request->request->getString('_token'))) {
            return $this->json(['error' => $this->translator->trans('guest_checkin.tab.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        return $this->linkResponse($reservation);
    }

    #[Route('/regenerate', name: 'reservations.guest_checkin.regenerate', methods: ['POST'])]
    public function regenerate(Request $request, Reservation $reservation): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::tokenId($reservation), $request->request->getString('_token'))) {
            return $this->json(['error' => $this->translator->trans('guest_checkin.tab.invalid_token')], Response::HTTP_FORBIDDEN);
        }

        if ($this->linkService->isOfferable($reservation)) {
            $this->linkService->regenerate($reservation);
        }

        return $this->linkResponse($reservation);
    }

    #[Route('/apply', name: 'reservations.guest_checkin.apply', methods: ['POST'])]
    public function apply(Request $request, Reservation $reservation, GuestCheckInRepository $repository, GuestCheckInApplyService $applyService): Response
    {
        $checkIn = $repository->findOneByReservation($reservation);
        if (!$this->isCsrfTokenValid(self::tokenId($reservation), $request->request->getString('_token'))) {
            $this->addFlash('warning', 'guest_checkin.tab.invalid_token');
        } elseif (null === $checkIn || GuestCheckInStatus::SUBMITTED !== $checkIn->getStatus()) {
            $this->addFlash('warning', 'guest_checkin.apply.nothing');
        } else {
            $applyRequest = new GuestCheckInApplyRequest(
                mainTarget: $request->request->getString('mainTarget'),
                setAsBooker: $request->request->getBoolean('setAsBooker'),
                companionTargets: array_values(array_map('strval', $request->request->all('companionTargets'))),
            );

            try {
                foreach ($applyService->apply($checkIn, $applyRequest) as $warning) {
                    $this->addFlash('warning', $warning);
                }
                $this->addFlash('success', 'guest_checkin.apply.success');
            } catch (\InvalidArgumentException) {
                $this->addFlash('warning', 'guest_checkin.apply.failed');
            }
        }

        return $this->showReservation($reservation);
    }

    /** Drops the submission; the guest can fill in the form again with the same link. */
    #[Route('/discard', name: 'reservations.guest_checkin.discard', methods: ['DELETE'])]
    public function discard(Request $request, Reservation $reservation, GuestCheckInRepository $repository, EntityManagerInterface $em): Response
    {
        $checkIn = $repository->findOneByReservation($reservation);
        // Token id as rendered by the shared delete popover ('delete' ~ id).
        if ($this->isCsrfTokenValid('delete'.self::tokenId($reservation), $request->request->getString('_token')) && null !== $checkIn) {
            $checkIn->discard();
            $em->flush();
            $this->addFlash('success', 'guest_checkin.discard.success');
        }

        return $this->showReservation($reservation);
    }

    public static function tokenId(Reservation $reservation): string
    {
        return 'guest-checkin-'.$reservation->getId();
    }

    private function linkResponse(Reservation $reservation): JsonResponse
    {
        $url = $this->linkService->url($reservation);
        if (null === $url) {
            return $this->json(['error' => $this->translator->trans('guest_checkin.tab.link_unavailable')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['url' => $url, 'qr' => $this->linkService->qrDataUri($url, 300)]);
    }

    private function showReservation(Reservation $reservation): Response
    {
        return $this->forward(ReservationServiceController::class.'::getReservationAction', ['id' => $reservation->getId()], ['tab' => 'checkin']);
    }
}
