<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\GuestCheckInConfig;
use App\Repository\GuestCheckInConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

class GuestCheckInConfigService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuestCheckInConfigRepository $repository,
    ) {
    }

    /** Return the singleton config row and create a default row on first access (settings page). */
    public function getConfig(): GuestCheckInConfig
    {
        $config = $this->repository->findSingleton();
        if ($config instanceof GuestCheckInConfig) {
            return $config;
        }

        $config = new GuestCheckInConfig();
        $this->em->persist($config);
        $this->em->flush();

        return $config;
    }

    /**
     * Read-only access for public requests and template rendering: never writes, so a missing
     * row simply means the feature is off.
     */
    public function findConfig(): ?GuestCheckInConfig
    {
        return $this->repository->findSingleton();
    }

    public function isEnabled(): bool
    {
        return true === $this->findConfig()?->isEnabled();
    }

    public function saveConfig(GuestCheckInConfig $config): void
    {
        $this->em->persist($config);
        $this->em->flush();
    }
}
