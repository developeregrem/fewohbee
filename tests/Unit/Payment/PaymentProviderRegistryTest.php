<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Dto\CreatePaymentRequest;
use App\Payment\Dto\PaymentInitiation;
use App\Payment\Dto\PaymentStatusSnapshot;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\ProviderCapability;
use App\Payment\Exception\PaymentProviderException;
use App\Payment\Provider\PaymentProviderInterface;
use App\Payment\Provider\PaymentProviderRegistry;
use PHPUnit\Framework\TestCase;

final class PaymentProviderRegistryTest extends TestCase
{
    public function testGetActiveReturnsConfiguredProvider(): void
    {
        $providerA = $this->makeProvider('alpha');
        $providerB = $this->makeProvider('beta');

        $registry = new PaymentProviderRegistry([$providerA, $providerB], 'beta');

        self::assertSame($providerB, $registry->getActive());
    }

    public function testGetActiveThrowsWhenNoneConfigured(): void
    {
        $registry = new PaymentProviderRegistry([$this->makeProvider('alpha')], null);

        $this->expectException(PaymentProviderException::class);
        $registry->getActive();
    }

    public function testGetActiveThrowsWhenConfiguredProviderUnknown(): void
    {
        $registry = new PaymentProviderRegistry([$this->makeProvider('alpha')], 'gamma');

        $this->expectException(PaymentProviderException::class);
        $registry->getActive();
    }

    public function testGetByIdReturnsProvider(): void
    {
        $providerA = $this->makeProvider('alpha');
        $registry = new PaymentProviderRegistry([$providerA], null);

        self::assertSame($providerA, $registry->get('alpha'));
        self::assertTrue($registry->has('alpha'));
        self::assertFalse($registry->has('missing'));
    }

    private function makeProvider(string $id): PaymentProviderInterface
    {
        return new class($id) implements PaymentProviderInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function supports(ProviderCapability $capability): bool
            {
                return false;
            }

            public function createPayment(CreatePaymentRequest $request): PaymentInitiation
            {
                return new PaymentInitiation('x', null);
            }

            public function fetchPaymentStatus(string $providerPaymentId): PaymentStatusSnapshot
            {
                return new PaymentStatusSnapshot(PaymentStatus::PENDING);
            }
        };
    }
}
