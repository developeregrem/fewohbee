<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountingSettings;
use App\Repository\AccountingSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountingSettingsService
{
    private ?AccountingSettings $cachedSettings = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountingSettingsRepository $repository,
    ) {
    }

    /** Return the singleton settings row and create a default row on first access. */
    public function getSettings(): AccountingSettings
    {
        if ($this->cachedSettings instanceof AccountingSettings) {
            return $this->cachedSettings;
        }

        $settings = $this->repository->findSingleton();
        if ($settings instanceof AccountingSettings) {
            $this->cachedSettings = $settings;

            return $this->cachedSettings;
        }

        $settings = new AccountingSettings();
        $this->em->persist($settings);
        $this->em->flush();

        $this->cachedSettings = $settings;

        return $this->cachedSettings;
    }

    public function saveSettings(AccountingSettings $settings): void
    {
        $this->em->persist($settings);
        $this->em->flush();
        $this->cachedSettings = $settings;
    }
}
