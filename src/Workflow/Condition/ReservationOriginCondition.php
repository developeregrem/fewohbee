<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Reservation;

class ReservationOriginCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'reservation.origin_is';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.reservation_origin_is';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key'   => 'originId',
                'type'  => 'reservation_origin_select',
                'label' => 'workflow.condition.reservation_origin_is',
            ],
        ];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Reservation) {
            return false;
        }

        $expectedId = (int) ($config['originId'] ?? -1);
        $origin = $entity->getReservationOrigin();

        return null !== $origin && $origin->getId() === $expectedId;
    }
}
