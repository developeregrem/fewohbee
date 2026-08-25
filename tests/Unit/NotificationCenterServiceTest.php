<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Dto\NotificationItem;
use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Notification\NotificationProviderRegistry;
use App\Service\NotificationCenterService;
use PHPUnit\Framework\TestCase;

final class NotificationCenterServiceTest extends TestCase
{
    public function testAnEmptyRegistryYieldsAnEmptySummary(): void
    {
        $summary = $this->service()->getSummary($this->user());

        self::assertTrue($summary->isEmpty());
        self::assertSame(0, $summary->total);
        self::assertNull($summary->severity);
    }

    public function testCountsAreSummedAcrossProviders(): void
    {
        $service = $this->service(
            $this->provider('a', count: 3),
            $this->provider('b', count: 4),
        );

        self::assertSame(7, $service->getSummary($this->user())->total);
    }

    public function testTheLoudestSeverityWins(): void
    {
        $service = $this->service(
            $this->provider('info', count: 9, severity: NotificationSeverity::INFO),
            $this->provider('critical', count: 1, severity: NotificationSeverity::CRITICAL),
            $this->provider('warning', count: 5, severity: NotificationSeverity::WARNING),
        );

        // A single conflict must still turn the bell red among louder-counted noise.
        self::assertSame(NotificationSeverity::CRITICAL, $service->getSummary($this->user())->severity);
        self::assertSame('bg-danger', $service->getSummary($this->user())->badgeClass());
    }

    public function testInvisibleProvidersAreNotCounted(): void
    {
        $service = $this->service(
            $this->provider('visible', count: 2),
            $this->provider('hidden', count: 100, visible: false),
        );

        self::assertSame(2, $service->getSummary($this->user())->total);
    }

    public function testAProviderWithNothingToSayDoesNotSetTheSeverity(): void
    {
        $service = $this->service(
            $this->provider('empty', count: 0, severity: NotificationSeverity::CRITICAL),
            $this->provider('info', count: 1, severity: NotificationSeverity::INFO),
        );

        self::assertSame(NotificationSeverity::INFO, $service->getSummary($this->user())->severity);
    }

    public function testTheSummaryIsMemoisedPerUser(): void
    {
        $provider = new class implements NotificationProviderInterface {
            public int $calls = 0;

            public function getKey(): string
            {
                return 'counting';
            }

            public function isVisibleFor(User $user): bool
            {
                return true;
            }

            public function countUnread(User $user): int
            {
                ++$this->calls;

                return 1;
            }

            public function getSeverity(User $user): NotificationSeverity
            {
                return NotificationSeverity::INFO;
            }

            public function getItems(User $user, int $limit): array
            {
                return [];
            }
        };

        $service = new NotificationCenterService(new NotificationProviderRegistry([$provider]));
        $user = $this->user();

        $service->getSummary($user);
        $service->getSummary($user);
        $service->getSummary($user);

        // base.html.twig asks once per render; several asks must not mean several queries.
        self::assertSame(1, $provider->calls);
    }

    public function testGroupedItemsAreOrderedBySeverity(): void
    {
        $service = $this->service(
            $this->provider('info', count: 1, severity: NotificationSeverity::INFO),
            $this->provider('critical', count: 1, severity: NotificationSeverity::CRITICAL),
            $this->provider('warning', count: 1, severity: NotificationSeverity::WARNING),
        );

        self::assertSame(
            ['critical', 'warning', 'info'],
            array_keys($service->getGroupedItems($this->user()))
        );
    }

    public function testGroupedItemsSkipProvidersWithoutEntries(): void
    {
        $service = $this->service(
            $this->provider('empty', count: 0),
            $this->provider('filled', count: 1),
        );

        self::assertSame(['filled'], array_keys($service->getGroupedItems($this->user())));
    }

    private function service(NotificationProviderInterface ...$providers): NotificationCenterService
    {
        return new NotificationCenterService(new NotificationProviderRegistry($providers));
    }

    private function provider(
        string $key,
        int $count,
        NotificationSeverity $severity = NotificationSeverity::WARNING,
        bool $visible = true,
    ): NotificationProviderInterface {
        return new class($key, $count, $severity, $visible) implements NotificationProviderInterface {
            public function __construct(
                private readonly string $key,
                private readonly int $count,
                private readonly NotificationSeverity $severity,
                private readonly bool $visible,
            ) {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function isVisibleFor(User $user): bool
            {
                return $this->visible;
            }

            public function countUnread(User $user): int
            {
                return $this->count;
            }

            public function getSeverity(User $user): NotificationSeverity
            {
                return $this->severity;
            }

            public function getItems(User $user, int $limit): array
            {
                if ($this->count < 1) {
                    return [];
                }

                return [new NotificationItem(
                    key: $this->key,
                    severity: $this->severity,
                    icon: 'fa-bell',
                    titleKey: 'notification.' . $this->key . '.title',
                    count: $this->count,
                )];
            }
        };
    }

    private function user(int $id = 1): User
    {
        return (new User())->setId($id);
    }
}
