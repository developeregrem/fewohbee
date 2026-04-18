<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @extends ServiceEntityRepository<WebauthnCredential>
 */
class WebauthnCredentialRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly RequestStack $requestStack,
    ) {
        parent::__construct($registry, WebauthnCredential::class);
    }

    /**
     * Persist a credential entity (insert or update).
     */
    public function save(WebauthnCredential $credential): void
    {
        $this->getEntityManager()->persist($credential);
        $this->getEntityManager()->flush();
    }

    /**
     * Persist a newly registered credential with auto-detected device
     * label (e.g. "macOS (Safari)", "iOS", "Windows (Chrome)") derived
     * from the current request's User-Agent header.
     */
    public function saveWithDeviceDetection(WebauthnCredential $credential): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $userAgent = $request?->headers->get('user-agent');
        $credential->setUserAgent($userAgent);
        $credential->setClientLabel($this->detectClientLabel($userAgent));

        $this->save($credential);
    }

    /**
     * Find all passkey credentials registered for a given user (by user ID).
     *
     * @return WebauthnCredential[]
     */
    public function findByUserHandle(string $userHandle): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userHandle = :userHandle')
            ->setParameter('userHandle', $userHandle)
            ->getQuery()
            ->getResult();
    }

    /**
     * Look up a credential by its raw (binary) public key credential ID.
     * The ID is base64-encoded before querying because the database
     * stores it in that format.
     */
    public function findOneByCredentialId(string $publicKeyCredentialId): ?WebauthnCredential
    {
        return $this->createQueryBuilder('c')
            ->where('c.publicKeyCredentialId = :publicKeyCredentialId')
            ->setParameter('publicKeyCredentialId', base64_encode($publicKeyCredentialId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Derive a human-readable device label from the User-Agent string
     * (e.g. "iOS (Safari)", "Android (Chrome)", "Windows (Firefox)").
     */
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
