<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Enum\NotificationSeverity;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Service\NotificationCenterService;
use App\Workflow\WorkflowSkippedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puts an entry in the notification bell.
 *
 * Works with every trigger on purpose: instead of writing code for each new kind
 * of notification, the operator wires one up under Settings → Automations. That
 * is why getSupportedTriggerTypes() is empty and the title is configurable.
 */
class CreateInAppNotificationAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly NotificationCenterService $notificationCenter,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'create_in_app_notification';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.create_in_app_notification';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class, Invoice::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        // Empty = every compatible trigger. Any event worth an email is worth a
        // bell entry, and the operator decides which.
        return [];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'note',
                'type' => 'text',
                'label' => 'workflow.form.notification_note',
                'help' => 'workflow.form.notification_note_help',
            ],
            [
                'key' => 'severity',
                'type' => 'select',
                'label' => 'workflow.form.notification_severity',
                'help' => 'workflow.form.notification_severity_help',
                'options' => [
                    ['value' => 'info', 'label' => 'workflow.form.notification_severity_info'],
                    ['value' => 'warning', 'label' => 'workflow.form.notification_severity_warning'],
                    ['value' => 'critical', 'label' => 'workflow.form.notification_severity_critical'],
                ],
            ],
            [
                'key' => 'requiredRole',
                'type' => 'select',
                'label' => 'workflow.form.notification_role',
                'help' => 'workflow.form.notification_role_help',
                'options' => [
                    ['value' => '', 'label' => 'workflow.form.notification_role_everyone'],
                    ['value' => 'ROLE_RESERVATIONS', 'label' => 'workflow.form.notification_role_reservations'],
                    ['value' => 'ROLE_INVOICES', 'label' => 'workflow.form.notification_role_invoices'],
                    ['value' => 'ROLE_OPERATIONS', 'label' => 'workflow.form.notification_role_operations'],
                    ['value' => 'ROLE_ADMIN', 'label' => 'workflow.form.notification_role_admin'],
                ],
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        $severity = NotificationSeverity::tryFrom((string) ($config['severity'] ?? 'info'))
            ?? NotificationSeverity::INFO;
        $requiredRole = '' === ($config['requiredRole'] ?? '') ? null : (string) $config['requiredRole'];
        $note = trim((string) ($config['note'] ?? ''));
        $note = '' === $note ? null : $note;

        if ($entity instanceof Reservation) {
            return $this->notifyReservation($entity, $severity, $requiredRole, $note, $context);
        }

        if ($entity instanceof Invoice) {
            return $this->notifyInvoice($entity, $severity, $requiredRole, $note);
        }

        throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
    }

    /** @param array<string, mixed> $context */
    private function notifyReservation(
        Reservation $reservation,
        NotificationSeverity $severity,
        ?string $requiredRole,
        ?string $note,
        array $context,
    ): string {
        $all = $context['allReservations'] ?? null;
        $count = is_array($all) && count($all) > 0 ? count($all) : 1;

        $appartment = $reservation->getAppartment();
        $params = [
            '%count%' => $count,
            '%from%' => $reservation->getStartDate()->format('d.m.Y'),
            '%room%' => null !== $appartment ? (string) $appartment->getNumber() : '–',
        ];

        // This action works with every trigger, so the wording must follow the
        // event, not the entity. A status change on an imported booking is not
        // "a new booking taken over from Booking.com", and a reminder three days
        // before arrival is not a new booking either.
        $trigger = (string) ($context['triggerType'] ?? '');
        $isNewBooking = in_array(
            $trigger,
            ['online_booking.created', 'calendar_import.created', 'reservation.created'],
            true
        );

        $import = $reservation->getCalendarSyncImport();
        if ($isNewBooking && null !== $import) {
            // Portals do not send guest details over iCal, so an imported booking
            // never has a booker. Printing "unknown guest" would be noise.
            $params['%source%'] = $import->getName();

            return $this->record(
                'calendar_import',
                'notification.stored.calendar_import',
                $params,
                $import->getName(),
                $severity,
                $requiredRole,
                $note,
                $reservation,
            );
        }

        $booker = $reservation->getBooker();
        $name = null !== $booker ? trim($booker->getFirstname() . ' ' . $booker->getLastname()) : '';

        // No booker means no name to show — a placeholder like "unknown guest"
        // is filler, not information. Separate keys rather than an empty
        // parameter, so the sentence does not end up with a dangling "from".
        $hasName = '' !== $name;
        if ($hasName) {
            $params['%name%'] = $name;
        }

        $titleKey = match (true) {
            // One booking can cover several rooms; naming just the first would
            // be wrong, so the count takes over.
            !$isNewBooking => $hasName
                ? 'notification.stored.reservation_generic'
                : 'notification.stored.reservation_generic_anonymous',
            $count > 1 => $hasName
                ? 'notification.stored.reservation_multi'
                : 'notification.stored.reservation_multi_anonymous',
            default => $hasName
                ? 'notification.stored.reservation'
                : 'notification.stored.reservation_anonymous',
        };

        return $this->record(
            'reservation',
            $titleKey,
            $params,
            $hasName ? $name : ('#' . $reservation->getId()),
            $severity,
            $requiredRole,
            $note,
            $reservation,
        );
    }

    /**
     * @param array<string, string|int> $params
     */
    private function record(
        string $type,
        string $titleKey,
        array $params,
        string $logLabel,
        NotificationSeverity $severity,
        ?string $requiredRole,
        ?string $note,
        Reservation $reservation,
    ): string {
        $this->notificationCenter->create(
            type: $type,
            titleKey: $titleKey,
            severity: $severity,
            params: $params,
            routeName: 'start',
            requiredRole: $requiredRole ?? 'ROLE_RESERVATIONS_RO',
            entityClass: Reservation::class,
            entityId: (string) $reservation->getId(),
            note: $note,
        );

        return $this->translator->trans('workflow.log.notification_created', ['%title%' => $logLabel]);
    }

    private function notifyInvoice(Invoice $invoice, NotificationSeverity $severity, ?string $requiredRole, ?string $note): string
    {
        $number = $invoice->getNumber() ?? (string) $invoice->getId();

        $this->notificationCenter->create(
            type: 'invoice',
            titleKey: 'notification.stored.invoice',
            severity: $severity,
            params: ['%number%' => $number],
            routeName: 'invoices.overview',
            requiredRole: $requiredRole ?? 'ROLE_INVOICES',
            entityClass: Invoice::class,
            entityId: (string) $invoice->getId(),
            note: $note,
        );

        return $this->translator->trans('workflow.log.notification_created', ['%title%' => $number]);
    }
}
