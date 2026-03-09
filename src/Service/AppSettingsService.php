<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AppSettings;
use App\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

class AppSettingsService
{
    private ?AppSettings $cachedSettings = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppSettingsRepository $repository,
        private readonly string $fromMail
    ) {
    }

    /** Return the singleton settings row and create a default row on first access. */
    public function getSettings(): AppSettings
    {
        if ($this->cachedSettings instanceof AppSettings) {
            return $this->cachedSettings;
        }

        $settings = $this->repository->findSingleton();
        if ($settings instanceof AppSettings) {
            $this->cachedSettings = $settings;

            return $this->cachedSettings;
        }

        $settings = new AppSettings();
        $this->em->persist($settings);
        $this->em->flush();

        $this->cachedSettings = $settings;

        return $this->cachedSettings;
    }

    public function saveSettings(AppSettings $settings): void
    {
        $this->em->persist($settings);
        $this->em->flush();
        $this->cachedSettings = $settings;
    }

    /** Resolve the effective notification email address (explicit setting or FROM_MAIL fallback). */
    public function getNotificationEmail(?AppSettings $settings = null): ?string
    {
        $settings ??= $this->getSettings();
        $email = $settings->getNotificationEmail();

        return (null !== $email && '' !== $email) ? $email : $this->fromMail;
    }
}
