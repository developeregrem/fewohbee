<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Invoice;
use Doctrine\ORM\EntityManagerInterface;

class InvoiceCreatedTrigger implements WorkflowTriggerInterface
{
    public function getType(): string
    {
        return 'invoice.created';
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.invoice_created';
    }

    public function getEntityClass(): ?string
    {
        return Invoice::class;
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function isEventDriven(): bool
    {
        return true;
    }

    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        return $em->getRepository(Invoice::class)->createQueryBuilder('i')
            ->orderBy('i.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        return [];
    }
}
