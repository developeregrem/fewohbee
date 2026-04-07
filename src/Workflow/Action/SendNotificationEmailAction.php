<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Reservation;
use App\Service\AppSettingsService;
use App\Service\MailService;
use App\Workflow\WorkflowSkippedException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sends a lightweight notification email to the hotelier.
 *
 * This action replaces the former BookingNotificationService and is used
 * by internal (system) workflows for online-booking and calendar-import
 * notifications. The email body is built from translation keys, not from
 * user-defined templates.
 */
class SendNotificationEmailAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly AppSettingsService $settingsService,
        private readonly MailService $mailService,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getType(): string
    {
        return 'send_notification_email';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.send_notification_email';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        return ['online_booking.created', 'calendar_import.created'];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        $to = $this->settingsService->getNotificationEmail();
        if (null === $to || '' === $to) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_notification_email'));
        }

        $allReservations = $context['allReservations'] ?? null;

        if (is_array($allReservations) && count($allReservations) > 0) {
            return $this->sendOnlineBookingNotification($to, $allReservations);
        }

        if ($entity instanceof Reservation) {
            if (null !== $entity->getCalendarSyncImport()) {
                return $this->sendCalendarImportNotification($to, $entity);
            }

            return $this->sendOnlineBookingNotification($to, [$entity]);
        }

        throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
    }

    /** @param Reservation[] $reservations */
    private function sendOnlineBookingNotification(string $to, array $reservations): string
    {
        $subject = $this->translator->trans('app_settings.notification_mail.online_booking_subject');
        $body = $this->buildOnlineBookingBody($reservations);
        $this->mailService->sendHTMLMail($to, $subject, $body);

        return $this->translator->trans('workflow.log.notification_online_booking_sent', ['%recipient%' => $to, '%count%' => count($reservations)]);
    }

    private function sendCalendarImportNotification(string $to, Reservation $reservation): string
    {
        $subject = $this->translator->trans('app_settings.notification_mail.calendar_import_subject');
        $body = $this->buildCalendarImportBody($reservation);
        $this->mailService->sendHTMLMail($to, $subject, $body);

        return $this->translator->trans('workflow.log.notification_calendar_import_sent', ['%recipient%' => $to]);
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
