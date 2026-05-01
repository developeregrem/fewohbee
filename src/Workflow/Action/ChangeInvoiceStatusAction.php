<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Event\InvoiceStatusChangedEvent;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ChangeInvoiceStatusAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getType(): string
    {
        return 'change_invoice_status';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.change_invoice_status';
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
            fn (InvoiceStatus $s) => ['value' => $s->value, 'label' => $s->labelKey()],
            InvoiceStatus::cases()
        );

        return [
            [
                'key' => 'status',
                'type' => 'select',
                'label' => 'workflow.action.change_invoice_status',
                'options' => $options,
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        if (!$entity instanceof Invoice) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
        }

        $newStatus = (int) ($config['status'] ?? -1);
        $invoiceStatus = InvoiceStatus::fromStatus($newStatus);

        if (null === $invoiceStatus) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_invalid_config'));
        }

        $previousStatus = (int) $entity->getStatus();
        $entity->setStatus($newStatus);
        $this->em->flush();

        if ($previousStatus !== $newStatus) {
            $this->eventDispatcher->dispatch(new InvoiceStatusChangedEvent($entity, $previousStatus));
        }

        return $this->translator->trans('workflow.log.invoice_status_changed', [
            '%number%' => $entity->getNumber(),
            '%status%' => $this->translator->trans($invoiceStatus->labelKey()),
        ]);
    }
}
