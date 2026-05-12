<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentTransaction;
use App\Payment\Enum\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentTransaction>
 */
class PaymentTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentTransaction::class);
    }

    public function findOneByProviderAndProviderPaymentId(string $providerId, string $providerPaymentId): ?PaymentTransaction
    {
        return $this->findOneBy([
            'providerId' => $providerId,
            'providerPaymentId' => $providerPaymentId,
        ]);
    }

    /** @return PaymentTransaction[] */
    public function findPending(int $limit = 200): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', [PaymentStatus::PENDING, PaymentStatus::INITIATED])
            ->orderBy('p.updatedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
