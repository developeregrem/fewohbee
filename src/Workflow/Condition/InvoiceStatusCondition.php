<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Invoice;
use App\Enum\InvoiceStatus;

class InvoiceStatusCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'invoice.status_is';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.invoice_status_is';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getConfigSchema(): array
    {
        $options = array_map(
            fn (InvoiceStatus $s) => ['value' => $s->value, 'label' => $s->labelKey()],
            InvoiceStatus::cases()
        );

        return [
            [
                'key' => 'status',
                'type' => 'select',
                'label' => 'workflow.condition.invoice_status_is',
                'options' => $options,
            ],
        ];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Invoice) {
            return false;
        }

        $expectedStatus = (int) ($config['status'] ?? -1);

        return (int) $entity->getStatus() === $expectedStatus;
    }
}
