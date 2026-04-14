<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;

class PaymentMeansCodeCondition implements WorkflowConditionInterface
{
    public function getType(): string
    {
        return 'invoice.payment_means_is';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.invoice_payment_means_is';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getConfigSchema(): array
    {
        $options = array_map(
            fn (PaymentMeansCode $code) => ['value' => $code->value, 'label' => $code->name],
            PaymentMeansCode::cases()
        );

        return [
            [
                'key' => 'paymentMeansCode',
                'type' => 'select',
                'label' => 'workflow.condition.invoice_payment_means_is',
                'options' => $options,
            ],
        ];
    }

    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Invoice) {
            return false;
        }

        $expectedCode = (int) ($config['paymentMeansCode'] ?? -1);
        $actual = $entity->getPaymentMeans();

        if (null === $actual) {
            return false;
        }

        return $actual->value === $expectedCode;
    }
}
