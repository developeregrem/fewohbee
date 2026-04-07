<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Reservation;

class ReservationStatusCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'reservation.status_is';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.reservation_status_is';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'statusId',
                'type' => 'reservation_status_select',
                'label' => 'workflow.condition.reservation_status_is',
            ],
        ];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Reservation) {
            return false;
        }

        $expectedId = (int) ($config['statusId'] ?? -1);
        $status = $entity->getReservationStatus();

        return null !== $status && $status->getId() === $expectedId;
    }
}
