<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Invoice;
use App\Entity\Reservation;

/**
 * Matches the origin of the reservations an invoice was created for.
 *
 * An invoice can cover several reservations, and those may carry different
 * origins. The condition therefore matches as soon as one of them has the
 * selected origin.
 */
class InvoiceReservationOriginCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'invoice.reservation_origin_is';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.invoice_reservation_origin_is';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'   => 'originId',
                'type'  => 'reservation_origin_select',
                'label' => 'workflow.condition.invoice_reservation_origin_is',
            ],
        ];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Invoice) {
            return false;
        }

        $expectedId = (int) ($config['originId'] ?? -1);

        foreach ($entity->getReservations() as $reservation) {
            if (!$reservation instanceof Reservation) {
                continue;
            }

            $origin = $reservation->getReservationOrigin();
            if (null !== $origin && $origin->getId() === $expectedId) {
                return true;
            }
        }

        return false;
    }
}
