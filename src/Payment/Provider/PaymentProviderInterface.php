<?php

declare(strict_types=1);

namespace App\Payment\Provider;

use App\Payment\Dto\CreatePaymentRequest;
use App\Payment\Dto\PaymentInitiation;
use App\Payment\Dto\PaymentStatusSnapshot;
use App\Payment\Enum\ProviderCapability;
use App\Payment\Exception\PaymentProviderException;

interface PaymentProviderInterface
{
    /** Stable identifier for this provider (e.g. "payactive", "stripe"). */
    public function getId(): string;

    /** Whether this provider supports the given capability. */
    public function supports(ProviderCapability $capability): bool;

    /**
     * Initiate a payment with the provider.
     *
     * @throws PaymentProviderException
     */
    public function createPayment(CreatePaymentRequest $request): PaymentInitiation;

    /**
     * Fetch the current status of a previously created payment from the provider.
     *
     * @throws PaymentProviderException
     */
    public function fetchPaymentStatus(string $providerPaymentId): PaymentStatusSnapshot;
}
