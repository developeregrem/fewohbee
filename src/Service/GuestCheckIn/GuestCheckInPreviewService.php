<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\Appartment;
use App\Entity\GuestCheckIn;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sample check-in for the settings preview: shows administrators the guest page with their own
 * room, texts and options, without a real reservation or a link.
 */
class GuestCheckInPreviewService
{
    public const VIEW_FORM = 'form';
    public const VIEW_STAY = 'stay';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * A made-up stay of two guests in the first room, two weeks ahead; for VIEW_STAY already
     * sent. Nothing is persisted, and the caller must not flush. Null while no room exists.
     */
    public function sample(string $view): ?GuestCheckIn
    {
        $appartment = $this->em->getRepository(Appartment::class)->findOneBy([], ['id' => 'ASC']);
        if (!$appartment instanceof Appartment) {
            return null;
        }

        $start = \DateTime::createFromImmutable($this->clock->now())->modify('+14 days')->setTime(0, 0);
        $reservation = new Reservation();
        $reservation->setAppartment($appartment);
        $reservation->setReservationOrigin($this->em->getRepository(ReservationOrigin::class)->findOneBy([], ['id' => 'ASC']));
        $reservation->setStartDate($start);
        $reservation->setEndDate((clone $start)->modify('+3 days'));
        $reservation->setPersons(2);

        $checkIn = new GuestCheckIn($reservation, 'preview');
        if (self::VIEW_STAY === $view) {
            $lastname = $this->translator->trans('guest_checkin.settings.preview_sample_lastname');
            $reservation->setArrivalTime(new \DateTime('16:00'));
            $checkIn->recordSubmission([
                'v' => GuestCheckIn::PAYLOAD_VERSION,
                'arrivalTime' => '16:00',
                'mainGuest' => ['firstname' => $this->translator->trans('guest_checkin.settings.preview_sample_firstname'), 'lastname' => $lastname],
                'companions' => [['firstname' => $this->translator->trans('guest_checkin.settings.preview_sample_companion'), 'lastname' => $lastname]],
            ], $this->clock->now());
        }

        return $checkIn;
    }
}
