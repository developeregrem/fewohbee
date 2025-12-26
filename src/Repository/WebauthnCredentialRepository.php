<?php

namespace App\Repository;

use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Webauthn\Bundle\Repository\CanSaveCredentialSource;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebauthnCredentialRepository extends ServiceEntityRepository implements PublicKeyCredentialSourceRepositoryInterface, CanSaveCredentialSource
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RequestStack $requestStack
    ) {
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

        if ($publicKeyCredentialSource instanceof WebauthnCredential) {
            $request = $this->requestStack->getCurrentRequest();
            $userAgent = $request?->headers->get('user-agent');
            $publicKeyCredentialSource->setUserAgent($userAgent);
            $publicKeyCredentialSource->setClientLabel($this->detectClientLabel($userAgent));
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

    private function detectClientLabel(?string $userAgent): ?string
    {
        if (null === $userAgent) {
            return null;
        }

        $ua = strtolower($userAgent);
        $isSafari = str_contains($ua, 'safari') && !str_contains($ua, 'chrome');
        $isChrome = str_contains($ua, 'chrome');
        $isFirefox = str_contains($ua, 'firefox') || str_contains($ua, 'fxios');

        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            return 'iOS'.($isSafari ? ' (Safari)' : '');
        }

        if (str_contains($ua, 'android')) {
            return 'Android'.($isChrome ? ' (Chrome)' : '');
        }

        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) {
            return 'macOS'.($isSafari ? ' (Safari)' : ($isChrome ? ' (Chrome)' : ''));
        }

        if (str_contains($ua, 'windows')) {
            return 'Windows'.($isChrome ? ' (Chrome)' : ($isFirefox ? ' (Firefox)' : ''));
        }

        if (str_contains($ua, 'cros')) {
            return 'ChromeOS';
        }

        return null;
    }
}
