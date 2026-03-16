<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Send lightweight notification emails when new bookings are created. */
class BookingNotificationService
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly MailService $mailService,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    /** Notify on a new online booking (one or more reservations in the same booking group). */
    public function notifyOnlineBooking(array $reservations): void
    {
        $settings = $this->settingsService->getSettings();
        if (!$settings->isNotifyOnOnlineBooking()) {
            return;
        }

        $to = $this->settingsService->getNotificationEmail($settings);
        if (null === $to || '' === $to) {
            return;
        }

        $subject = $this->translator->trans('app_settings.notification_mail.online_booking_subject');
        $body = $this->buildOnlineBookingBody($reservations);
        $this->mailService->sendHTMLMail($to, $subject, $body);
    }

    /** Notify on a new reservation created via calendar import. */
    public function notifyCalendarImport(Reservation $reservation): void
    {
        $settings = $this->settingsService->getSettings();
        if (!$settings->isNotifyOnCalendarImport()) {
            return;
        }

        $to = $this->settingsService->getNotificationEmail($settings);
        if (null === $to || '' === $to) {
            return;
        }

        $subject = $this->translator->trans('app_settings.notification_mail.calendar_import_subject');
        $body = $this->buildCalendarImportBody($reservation);
        $this->mailService->sendHTMLMail($to, $subject, $body);
    }

    /** @param Reservation[] $reservations */
    private function buildOnlineBookingBody(array $reservations): string
    {
        $greeting = $this->translator->trans('app_settings.notification_mail.greeting');
        $intro = $this->translator->trans('app_settings.notification_mail.online_booking_intro');

        $rows = '';
        foreach ($reservations as $reservation) {
            $rows .= $this->buildReservationRow($reservation);
        }

        $bookerInfo = '';
        $first = $reservations[0] ?? null;
        if ($first instanceof Reservation && null !== $first->getBooker()) {
            $booker = $first->getBooker();
            $bookerLabel = $this->translator->trans('app_settings.notification_mail.booker');
            $bookerName = trim($booker->getFirstname() . ' ' . $booker->getLastname());
            $bookerInfo = "<p><strong>{$bookerLabel}:</strong> {$this->esc($bookerName)}</p>";
        }

        return <<<HTML
        <p>{$greeting}</p>
        <p>{$intro}</p>
        {$rows}
        {$bookerInfo}
        {$this->buildFooter()}
        HTML;
    }

    private function buildCalendarImportBody(Reservation $reservation): string
    {
        $greeting = $this->translator->trans('app_settings.notification_mail.greeting');
        $intro = $this->translator->trans('app_settings.notification_mail.calendar_import_intro');

        $row = $this->buildReservationRow($reservation);

        $originInfo = '';
        if (null !== $reservation->getCalendarSyncImport()) {
            $originLabel = $this->translator->trans('app_settings.notification_mail.origin');
            $originName = $reservation->getCalendarSyncImport()->getName();
            $originInfo = "<p><strong>{$originLabel}:</strong> {$this->esc($originName)}</p>";
        }

        return <<<HTML
        <p>{$greeting}</p>
        <p>{$intro}</p>
        {$row}
        {$originInfo}
        {$this->buildFooter()}
        HTML;
    }

    private function buildReservationRow(Reservation $reservation): string
    {
        $roomLabel = $this->translator->trans('app_settings.notification_mail.room');
        $periodLabel = $this->translator->trans('app_settings.notification_mail.period');
        $guestsLabel = $this->translator->trans('app_settings.notification_mail.guests');

        $room = $reservation->getAppartment() ? $this->esc((string) $reservation->getAppartment()->getNumber()) : '–';
        $from = $reservation->getStartDate()?->format('d.m.Y') ?? '–';
        $to = $reservation->getEndDate()?->format('d.m.Y') ?? '–';
        $persons = $reservation->getPersons();

        return <<<HTML
        <table style="border-collapse:collapse;margin-bottom:8px">
            <tr><td style="padding:2px 8px 2px 0;font-weight:bold">{$roomLabel}:</td><td>{$room}</td></tr>
            <tr><td style="padding:2px 8px 2px 0;font-weight:bold">{$periodLabel}:</td><td>{$from} – {$to}</td></tr>
            <tr><td style="padding:2px 8px 2px 0;font-weight:bold">{$guestsLabel}:</td><td>{$persons}</td></tr>
        </table>
        HTML;
    }

    private function buildFooter(): string
    {
        $url = $this->urlGenerator->generate('dashboard.redirect', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $linkText = $this->translator->trans('app_settings.notification_mail.open_app');
        $footer = $this->translator->trans('app_settings.notification_mail.footer');

        return <<<HTML
        <p><a href="{$this->esc($url)}">{$linkText}</a></p>
        <hr>
        <p style="color:#888;font-size:12px">{$footer}</p>
        HTML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
