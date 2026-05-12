<?php

declare(strict_types=1);

namespace App\Payment\Adapter\Payactive;

use App\Payment\Dto\CreatePaymentRequest;
use App\Payment\Dto\PaymentInitiation;
use App\Payment\Dto\PaymentStatusSnapshot;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\ProviderCapability;
use App\Payment\Exception\PaymentProviderException;
use App\Payment\Provider\PaymentProviderInterface;

/**
 * Payactive adapter. Self-contained — depends only on the Core Payment module
 * and Symfony HTTP/Logger primitives. Extractable as a Composer package.
 *
 * Flow (per Payactive's canonical recipe):
 *   1) POST /customers       → customerId
 *   2) POST /payments        → paymentId
 *   3) GET  /payments/{id}/payment-link → URL for the guest
 *   4) GET  /payments/{id}   → polled state for status sync
 */
class PayactiveProvider implements PaymentProviderInterface
{
    public const ID = 'payactive';

    public function __construct(
        private readonly PayactiveClient $client,
    ) {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function supports(ProviderCapability $capability): bool
    {
        return match ($capability) {
            ProviderCapability::ONLINE_PAYMENT => true,
            // DIRECT_DEBIT is technically supported by Payactive but requires the
            // SEPA mandate flow which is not implemented here yet.
            // CARD_PREAUTH is not visible in the Payactive sandbox UI — pending clarification.
            // REFUND not yet wired up.
            default => false,
        };
    }

    public function createPayment(CreatePaymentRequest $request): PaymentInitiation
    {
        $customerId = $this->client->createCustomer([
            'emailAddress' => $request->customerEmail,
            'firstName' => $request->customerFirstName,
            'lastName' => $request->customerLastName,
            'type' => 'PERSON',
            'paymentMethod' => 'ONLINE_PAYMENT',
            'invitationType' => 'LINK',
            'externalRef' => $request->externalReference,
        ]);

        $paymentId = $this->client->createPayment([
            'paymentType' => 'PAYMENT_REQUEST',
            'customerId' => $customerId,
            'amount' => $request->amount,
            'currency' => $request->currency,
            'purpose' => $request->purpose,
            'externalReference' => $request->externalReference,
            'paymentMethod' => ['ONLINE_PAYMENT'],
            'paymentNotifications' => 'EMAIL',
        ]);

        $redirectUrl = $this->client->getPaymentLink($paymentId);

        return new PaymentInitiation(
            providerPaymentId: $paymentId,
            redirectUrl: $redirectUrl,
        );
    }

    public function fetchPaymentStatus(string $providerPaymentId): PaymentStatusSnapshot
    {
        $data = $this->client->getPayment($providerPaymentId);
        $rawState = isset($data['state']) && is_string($data['state']) ? $data['state'] : '';

        return new PaymentStatusSnapshot(
            status: $this->mapState($rawState),
            raw: $data,
        );
    }

    /** Map Payactive payment state to our normalized PaymentStatus enum. */
    private function mapState(string $state): PaymentStatus
    {
        return match ($state) {
            'CREATING', 'PENDING', 'MANUAL' => PaymentStatus::INITIATED,
            'COMPLETED', 'VERIFIED' => PaymentStatus::SETTLED,
            'ABORTED', 'ERROR' => PaymentStatus::FAILED,
            'CANCELLED' => PaymentStatus::CANCELLED,
            'REFUND_IN_PROGRESS', 'REFUND_COMPLETED' => PaymentStatus::REFUNDED,
            'CHARGED_BACK' => PaymentStatus::FAILED,
            '' => throw new PaymentProviderException('Payactive: payment response missing "state".'),
            default => PaymentStatus::INITIATED,
        };
    }
}
