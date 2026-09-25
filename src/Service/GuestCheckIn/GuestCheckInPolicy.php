<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Service\GuestCheckIn\Section\GuestCheckInSection;
use Symfony\Component\Clock\ClockInterface;

/**
 * Time and state rules of the online check-in. Pure decisions, no persistence.
 *
 * Dates are compared as calendar days in the application's timezone (date.timezone), the same
 * zone reservation dates are entered and displayed in.
 */
class GuestCheckInPolicy
{
    /** Upper bound of fellow-traveller blocks, keeps the public form finite. */
    public const MAX_COMPANIONS = 19;

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * - unavailable: feature off, reservation not saved or without room, non-blocking status
     *   (e.g. cancelled), or the departure day is over;
     * - locked: the arrival day is over or the hotelier took the data over;
     * - editable: otherwise.
     */
    public function linkState(Reservation $reservation, ?GuestCheckIn $checkIn, bool $enabled): GuestCheckInLinkState
    {
        if (!$enabled || null === $reservation->getId() || null === $reservation->getAppartment()) {
            return GuestCheckInLinkState::UNAVAILABLE;
        }

        $status = $reservation->getReservationStatus();
        if (null !== $status && !$status->isBlocking()) {
            return GuestCheckInLinkState::UNAVAILABLE;
        }

        $today = $this->today();
        if ($today > $reservation->getEndDate()->format('Y-m-d')) {
            return GuestCheckInLinkState::UNAVAILABLE;
        }

        if ($today > $reservation->getStartDate()->format('Y-m-d') || GuestCheckInStatus::APPLIED === $checkIn?->getStatus()) {
            return GuestCheckInLinkState::LOCKED;
        }

        return GuestCheckInLinkState::EDITABLE;
    }

    /**
     * Central gate for page sections: content meant for the stay (a door code, say) must not
     * appear before the guest checked in or outside the stay, whichever provider supplies it.
     */
    public function isSectionVisible(GuestCheckInSection $section, GuestCheckIn $checkIn): bool
    {
        if ($section->requiresSubmission && null === $checkIn->getFirstSubmittedAt()) {
            return false;
        }

        if ($section->duringStayOnly) {
            $reservation = $checkIn->getReservation();
            $today = $this->today();

            return $today >= $reservation->getStartDate()->format('Y-m-d') && $today <= $reservation->getEndDate()->format('Y-m-d');
        }

        return true;
    }

    /** Number of fellow-traveller blocks on the form: everyone staying, infants included, but the main guest. */
    public function companionCount(Reservation $reservation): int
    {
        return max(0, min($reservation->getTotalGuests() - 1, self::MAX_COMPANIONS));
    }

    private function today(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }
}
