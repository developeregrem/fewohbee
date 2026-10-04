<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\ReceiptProposalStatus;
use App\Entity\ReceiptProposal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReceiptProposal>
 */
class ReceiptProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReceiptProposal::class);
    }

    public function countOpen(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :open')
            ->setParameter('open', ReceiptProposalStatus::OPEN)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Keep past proposals accessible after MCP has been switched off. */
    public function hasAny(): bool
    {
        return null !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<ReceiptProposal> open proposals, oldest receipt first */
    public function findOpen(): array
    {
        return $this->findBy(['status' => ReceiptProposalStatus::OPEN], ['receiptDate' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<ReceiptProposal> the latest decided proposals, newest decision first */
    public function findRecentlyDecided(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status != :open')
            ->setParameter('open', ReceiptProposalStatus::OPEN)
            ->orderBy('p.decidedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** An open proposal for the same receipt, so a repeated submission does not create a second one. */
    public function findOpenDuplicate(string $supplier, \DateTimeImmutable $receiptDate, string $total, string $receiptNumber): ?ReceiptProposal
    {
        return $this->findOneBy([
            'status' => ReceiptProposalStatus::OPEN,
            'supplier' => $supplier,
            'receiptDate' => $receiptDate,
            'total' => $total,
            'receiptNumber' => $receiptNumber,
        ]);
    }
}
