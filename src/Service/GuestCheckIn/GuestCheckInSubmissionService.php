<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Dto\GuestCheckIn\GuestCheckInCompanion;
use App\Dto\GuestCheckIn\GuestCheckInGuest;
use App\Dto\GuestCheckIn\GuestCheckInSubmission;
use App\Entity\GuestCheckIn;
use App\Event\GuestCheckInSubmittedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Stores what a guest entered on the public check-in form.
 *
 * Only the check-in record and the reservation's announced arrival time change here; guest
 * records are updated once the hotelier took the data over (GuestCheckInApplyService).
 */
class GuestCheckInSubmissionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $installationLocale,
    ) {
    }

    /**
     * An ID number left empty keeps the one sent before, and taking over leaves the one in the
     * guest records alone: stored numbers are never shown again, so a guest correcting another
     * field cannot be asked to retype it.
     */
    public function submit(GuestCheckIn $checkIn, GuestCheckInSubmission $submission, string $locale): void
    {
        $firstSubmission = null === $checkIn->getFirstSubmittedAt();
        $previousIdNumber = $checkIn->getPayload()['mainGuest']['idNumber'] ?? null;

        $payload = $this->buildPayload($submission, $locale);
        if (null === $payload['mainGuest']['idNumber'] && \is_string($previousIdNumber)) {
            $payload['mainGuest']['idNumber'] = $previousIdNumber;
        }

        $reservation = $checkIn->getReservation();
        $reservation->setArrivalTime(null !== $payload['arrivalTime'] ? new \DateTime($payload['arrivalTime']) : null);
        $checkIn->recordSubmission($payload, $this->clock->now());
        $this->em->flush();

        // Workflows run synchronously and may send mails; they must use the installation's
        // language, not the one the guest picked for the form. Dispatching only after the flush
        // keeps a workflow's own flush from committing half of this submission.
        $this->localeSwitcher->runWithLocale(
            $this->installationLocale,
            fn () => $this->dispatcher->dispatch(new GuestCheckInSubmittedEvent($reservation, $checkIn, $firstSubmission)),
        );
    }

    /**
     * @return array{v: int, locale: string, arrivalTime: ?string, message: ?string, mainGuest: array<string, mixed>, companions: list<array<string, mixed>>}
     */
    private function buildPayload(GuestCheckInSubmission $submission, string $locale): array
    {
        $guest = $submission->mainGuest;
        $companions = array_values(array_filter(
            $submission->companions,
            static fn (GuestCheckInCompanion $companion): bool => !$companion->isEmpty(),
        ));

        return [
            'v' => GuestCheckIn::PAYLOAD_VERSION,
            'locale' => $locale,
            'arrivalTime' => self::clean($submission->arrivalTime),
            'message' => self::clean($submission->message, true),
            'mainGuest' => $this->guestPayload($guest),
            'companions' => array_map(static fn (GuestCheckInCompanion $companion): array => [
                'firstname' => self::clean($companion->firstname),
                'lastname' => self::clean($companion->lastname),
                'birthday' => $companion->birthday?->format('Y-m-d'),
                'nationality' => self::clean($companion->nationality),
                // Null: lives with the main guest.
                'address' => $companion->hasOwnAddress() ? [
                    'street' => self::clean($companion->street),
                    'zip' => self::clean($companion->zip),
                    'city' => self::clean($companion->city),
                    'country' => self::clean($companion->country),
                ] : null,
            ], $companions),
        ];
    }

    /** @return array<string, mixed> */
    private function guestPayload(GuestCheckInGuest $guest): array
    {
        return [
            'salutation' => self::clean($guest->salutation),
            'firstname' => self::clean($guest->firstname),
            'lastname' => self::clean($guest->lastname),
            'birthday' => $guest->birthday?->format('Y-m-d'),
            'nationality' => self::clean($guest->nationality),
            'idType' => $guest->idType?->value,
            'idNumber' => self::clean($guest->idNumber),
            'address' => [
                'street' => self::clean($guest->street),
                'zip' => self::clean($guest->zip),
                'city' => self::clean($guest->city),
                'country' => self::clean($guest->country),
            ],
            'email' => self::clean($guest->email),
            'phone' => self::clean($guest->phone),
        ];
    }

    /** Strips control and invisible formatting characters; line breaks survive only in free text. */
    private static function clean(?string $value, bool $multiline = false): ?string
    {
        if (null === $value) {
            return null;
        }

        $pattern = $multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u';
        $value = trim((string) preg_replace($pattern, '', $value));

        return '' === $value ? null : $value;
    }
}
