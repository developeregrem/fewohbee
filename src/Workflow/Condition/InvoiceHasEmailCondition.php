<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Invoice;

class InvoiceHasEmailCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'invoice.has_email';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.invoice_has_email';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Invoice) {
            return false;
        }

        $email = $entity->getEmail();

        return null !== $email && '' !== trim($email);
    }
}
