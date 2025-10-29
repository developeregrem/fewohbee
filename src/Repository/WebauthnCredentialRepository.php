<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\Bundle\Repository\CanSaveCredentialSource;
use App\Entity\WebauthnCredential;

final class WebauthnCredentialRepository extends ServiceEntityRepository implements PublicKeyCredentialSourceRepositoryInterface, CanSaveCredentialSource
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        if (!$publicKeyCredentialSource instanceof WebauthnCredential) {
            $publicKeyCredentialSource = new WebauthnCredential(
                $publicKeyCredentialSource->publicKeyCredentialId,
                $publicKeyCredentialSource->type,
                $publicKeyCredentialSource->transports,
                $publicKeyCredentialSource->attestationType,
                $publicKeyCredentialSource->trustPath,
                $publicKeyCredentialSource->aaguid,
                $publicKeyCredentialSource->credentialPublicKey,
                $publicKeyCredentialSource->userHandle,
                $publicKeyCredentialSource->counter
            );
        }
        $this->getEntityManager()->persist($publicKeyCredentialSource);
        $this->getEntityManager()->flush();
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        return $this->createQueryBuilder('c')
            ->select('c')
            ->where('c.userHandle = :userHandle')
            ->setParameter(':userHandle', $publicKeyCredentialUserEntity->id)
            ->getQuery()
            ->execute();
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        return $this->createQueryBuilder('c')
            ->select('c')
            ->where('c.publicKeyCredentialId = :publicKeyCredentialId')
            ->setParameter(':publicKeyCredentialId', base64_encode($publicKeyCredentialId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
