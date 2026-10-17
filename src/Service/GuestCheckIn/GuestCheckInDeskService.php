<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Event\GuestCheckInConfirmedEvent;
use App\Repository\GuestCheckInRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Check-in at the desk for guests who did not check in online: staff mark it as done, so the
 * reservation no longer shows up as open, invitations stop and the guest's link shows the stay.
 */
class GuestCheckInDeskService
{
    public function __construct(
        private readonly GuestCheckInRepository $repository,
        private readonly GuestCheckInConfigService $configService,
        private readonly GuestCheckInTokenSigner $signer,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * False when there is nothing to mark: feature off, already checked in, or an online
     * submission waits for review (that one is confirmed or discarded instead). Fires the same
     * event as a confirmed online check-in.
     */
    public function markCheckedIn(Reservation $reservation): bool
    {
        if (!$this->configService->isEnabled() || null === $reservation->getId()) {
            return false;
        }

        // Most guests checking in at the desk never got a link, so the row may not exist yet.
        $this->repository->ensureSelector($reservation->getId(), $this->signer->newSelector(), $this->clock->now());
        $checkIn = $this->repository->findOneByReservation($reservation);
        if (null === $checkIn || GuestCheckInStatus::OPEN !== $checkIn->getStatus()) {
            return false;
        }

        $checkIn->markCheckedInAtDesk($this->clock->now());
        $this->em->flush();
        $this->dispatcher->dispatch(new GuestCheckInConfirmedEvent($reservation, $checkIn));

        return true;
    }

    /** False unless the reservation was checked in at the desk. */
    public function undo(Reservation $reservation): bool
    {
        $checkIn = $this->repository->findOneByReservation($reservation);
        if (!$checkIn instanceof GuestCheckIn || !$checkIn->isCheckedInAtDesk()) {
            return false;
        }

        $checkIn->undoDeskCheckIn();
        $this->em->flush();

        return true;
    }
}
