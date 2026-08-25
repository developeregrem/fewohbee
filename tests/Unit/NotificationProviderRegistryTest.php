<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\NotificationSeverity;
use App\Entity\User;
use App\Notification\NotificationProviderInterface;
use App\Notification\NotificationProviderRegistry;
use PHPUnit\Framework\TestCase;

final class NotificationProviderRegistryTest extends TestCase
{
    public function testProvidersAreKeyedByTheirIdentifier(): void
    {
        $registry = new NotificationProviderRegistry([$this->provider('alpha'), $this->provider('beta')]);

        self::assertSame(['alpha', 'beta'], array_keys($registry->all()));
        self::assertTrue($registry->has('alpha'));
        self::assertFalse($registry->has('gamma'));
        self::assertSame('beta', $registry->get('beta')->getKey());
    }

    public function testAnUnknownKeyFailsLoudly(): void
    {
        $registry = new NotificationProviderRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown notification provider "nope".');

        $registry->get('nope');
    }

    private function provider(string $key): NotificationProviderInterface
    {
        return new class($key) implements NotificationProviderInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function isVisibleFor(User $user): bool
            {
                return true;
            }

            public function countUnread(User $user): int
            {
                return 0;
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
    }
}
