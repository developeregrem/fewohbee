<?php

declare(strict_types=1);

namespace App\Service\GuestCheckIn;

use App\Entity\GuestCheckInConfig;
use App\Repository\GuestCheckInConfigRepository;
use App\Repository\OnlineBookingConfigRepository;
use App\Service\AppSettingsService;
use Doctrine\ORM\EntityManagerInterface;

class GuestCheckInConfigService
{
    private const DEFAULT_ACCENT_COLOR = '#1f6feb';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuestCheckInConfigRepository $repository,
        private readonly AppSettingsService $appSettingsService,
        private readonly OnlineBookingConfigRepository $onlineBookingConfigRepository,
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

    /**
     * Salutations the guest form offers: the configured ones as stored (untranslated), blanks
     * dropped.
     *
     * @return list<string>
     */
    public function offeredSalutations(): array
    {
        return array_values(array_filter(
            $this->appSettingsService->getSettings()->getCustomerSalutations(),
            static fn (string $salutation): bool => '' !== trim($salutation),
        ));
    }

    /** Same accent colour as the online booking page, read without creating its settings row. */
    public function pageAccentColor(): string
    {
        $color = $this->onlineBookingConfigRepository->findSingleton()?->getThemePrimaryColor();

        return \is_string($color) && 1 === preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : self::DEFAULT_ACCENT_COLOR;
    }
}
