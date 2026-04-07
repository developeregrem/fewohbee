<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Reservation;

class HasBookerEmailCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'reservation.has_booker_email';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.reservation_has_booker_email';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Reservation) {
            return false;
        }

        $booker = $entity->getBooker();
        if (null === $booker) {
            return false;
        }

        foreach ($booker->getCustomerAddresses() as $address) {
            $email = $address->getEmail();
            if (null !== $email && '' !== trim($email)) {
                return true;
            }
        }

        return false;
    }
}
