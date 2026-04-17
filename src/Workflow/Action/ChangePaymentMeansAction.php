<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChangePaymentMeansAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getType(): string
    {
        return 'change_payment_means';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.change_payment_means';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Invoice::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        return [];
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
                'label' => 'workflow.action.change_payment_means',
                'options' => $options,
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        if (!$entity instanceof Invoice) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
        }

        $codeValue = (int) ($config['paymentMeansCode'] ?? -1);
        $paymentMeansCode = PaymentMeansCode::tryFrom($codeValue);

        if (null === $paymentMeansCode) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_invalid_config'));
        }

        $entity->setPaymentMeans($paymentMeansCode);
        $this->em->flush();

        return $this->translator->trans('workflow.log.payment_means_changed', [
            '%number%' => $entity->getNumber(),
            '%code%' => $paymentMeansCode->name,
        ]);
    }
}
