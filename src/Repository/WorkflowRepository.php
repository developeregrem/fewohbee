<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use App\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workflow::class);
    }

    /** @return Workflow[] */
    public function findActiveByTriggerType(string $triggerType): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.triggerType = :triggerType')
            ->andWhere('w.isEnabled = true')
            ->setParameter('triggerType', $triggerType)
            ->orderBy('w.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findBySystemCode(string $systemCode): ?Workflow
    {
        return $this->findOneBy(['systemCode' => $systemCode]);
    }

    public function countCreateBookingEntryAccountReferences(AccountingAccount $account): int
    {
        $accountId = $account->getId();
        if (null === $accountId) {
            return 0;
        }

        $references = 0;
        foreach ($this->findBy(['actionType' => 'create_booking_entry']) as $workflow) {
            $config = $workflow->getActionConfig();
            $debitAccountId = (int) ($config['debitAccountId'] ?? 0);
            $fallbackCreditAccountId = (int) ($config['fallbackCreditAccountId'] ?? 0);

            if ($debitAccountId === $accountId || $fallbackCreditAccountId === $accountId) {
                ++$references;
            }
        }

        return $references;
    }

    /** @return Workflow[] */
    public function findSystemWorkflows(): array
    {
        return $this->findBy(['isSystem' => true], ['priority' => 'DESC']);
    }

    /** @return Workflow[] */
    public function findUserWorkflows(): array
    {
        return $this->findBy(['isSystem' => false], ['name' => 'ASC']);
    }

    /** @return Workflow[] */
    public function findActiveByTriggerTypes(array $triggerTypes): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.triggerType IN (:triggerTypes)')
            ->andWhere('w.isEnabled = true')
            ->setParameter('triggerTypes', $triggerTypes)
            ->orderBy('w.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
