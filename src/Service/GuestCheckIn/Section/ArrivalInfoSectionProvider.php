<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn\Section;

use App\Entity\GuestCheckIn;
use App\Entity\Subsidiary;

/** The house's check-in window and arrival note, as maintained on the branch. */
final class ArrivalInfoSectionProvider implements GuestCheckInSectionProviderInterface
{
    public function getSections(GuestCheckIn $checkIn): iterable
    {
        $subsidiary = $checkIn->getReservation()->getAppartment()?->getObject();
        if (!$subsidiary instanceof Subsidiary) {
            return;
        }

        $hasTimes = null !== $subsidiary->getCheckInFrom() || null !== $subsidiary->getCheckOutUntil();
        if (!$hasTimes && null === $subsidiary->getCheckInNote()) {
            return;
        }

        yield new GuestCheckInSection('arrival_info', 'GuestCheckIn/public/_section_arrival_info.html.twig', [
            'subsidiary' => $subsidiary,
        ]);
    }
}
