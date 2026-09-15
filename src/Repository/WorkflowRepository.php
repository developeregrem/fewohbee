<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountingAccount;
use App\Entity\TaxRate;
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

    /**
     * Where an accounting account can be named in an action's config, by action
     * type. Every action booking to the journal belongs in here: what is missing
     * can be deleted while a workflow still points at it, after which the action
     * books without that account and says nothing.
     */
    private const ACCOUNT_CONFIG_KEYS = [
        'create_booking_entry' => ['debitAccountId', 'fallbackCreditAccountId'],
        'create_percentage_entry' => ['debitAccountId', 'creditAccountId'],
    ];

    /** Where a tax rate can be named in an action's config; see the accounts above. */
    private const TAX_RATE_CONFIG_KEYS = [
        'create_percentage_entry' => ['taxRateId'],
    ];

    public function countActionAccountReferences(AccountingAccount $account): int
    {
        return $this->countConfigReferences(self::ACCOUNT_CONFIG_KEYS, $account->getId());
    }

    public function countActionTaxRateReferences(TaxRate $taxRate): int
    {
        return $this->countConfigReferences(self::TAX_RATE_CONFIG_KEYS, $taxRate->getId());
    }

    /**
     * How many workflows name this id under any of the given config keys.
     *
     * @param array<string, string[]> $keysByActionType
     */
    private function countConfigReferences(array $keysByActionType, ?int $id): int
    {
        if (null === $id) {
            return 0;
        }

        $references = 0;
        foreach ($keysByActionType as $actionType => $keys) {
            foreach ($this->findBy(['actionType' => $actionType]) as $workflow) {
                $config = $workflow->getActionConfig();
                foreach ($keys as $key) {
                    if ((int) ($config[$key] ?? 0) === $id) {
                        ++$references;
                        continue 2;
                    }
                }
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
