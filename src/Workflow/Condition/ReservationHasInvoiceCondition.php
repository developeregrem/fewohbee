<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Entity\Reservation;

/**
 * True when the reservation already has an invoice.
 *
 * Reservation triggers fire long before an invoice usually exists (a fresh online
 * booking has none at all), so workflows that attach the invoice need this guard
 * to stay silent instead of sending an email without its attachment.
 *
 * Config:
 *   status string – any (not cancelled) | open (status OPEN only)
 */
class ReservationHasInvoiceCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'reservation.has_invoice';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.reservation_has_invoice';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'status',
                'type' => 'select',
                'label' => 'workflow.condition.has_invoice.label',
                'default' => 'any',
                'options' => [
                    ['value' => 'any', 'label' => 'workflow.condition.has_invoice.any'],
                    ['value' => 'open', 'label' => 'workflow.condition.has_invoice.open'],
                ],
            ],
        ];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Reservation) {
            return false;
        }

        $onlyOpen = 'open' === ($config['status'] ?? 'any');

        // A booking may span several rooms; the invoice can hang on any of them.
        foreach ($this->resolveReservations($entity, $context) as $reservation) {
            foreach ($reservation->getInvoices() as $invoice) {
                if (!$invoice instanceof Invoice) {
                    continue;
                }
                $status = InvoiceStatus::fromStatus($invoice->getStatus());
                if (InvoiceStatus::CANCELED === $status) {
                    continue;
                }
                if (!$onlyOpen || InvoiceStatus::OPEN === $status) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return Reservation[] */
    private function resolveReservations(Reservation $entity, array $context): array
    {
        $group = $context['allReservations'] ?? [];
        if (!is_array($group) || [] === $group) {
            return [$entity];
        }

        foreach ($group as $reservation) {
            if (!$reservation instanceof Reservation) {
                return [$entity];
            }
        }

        return $group;
    }
}
