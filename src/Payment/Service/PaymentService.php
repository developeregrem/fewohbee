<?php

declare(strict_types=1);

namespace App\Payment\Service;

use App\Entity\PaymentTransaction;
use App\Payment\Dto\CreatePaymentRequest;
use App\Payment\Dto\NormalizedWebhookEvent;
use App\Payment\Dto\PaymentInitiation;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\WebhookEventType;
use App\Payment\Event\PaymentCancelledEvent;
use App\Payment\Event\PaymentFailedEvent;
use App\Payment\Event\PaymentInitiatedEvent;
use App\Payment\Event\PaymentSettledEvent;
use App\Payment\Exception\PaymentProviderException;
use App\Payment\Provider\PaymentProviderRegistry;
use App\Payment\Webhook\WebhookHandlerRegistry;
use App\Repository\PaymentTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PaymentService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly PaymentProviderRegistry $providerRegistry,
        private readonly WebhookHandlerRegistry $webhookHandlerRegistry,
        private readonly EntityManagerInterface $em,
        private readonly PaymentTransactionRepository $transactionRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Initiate a payment via the currently active provider.
     * Returns the redirect URL to send the customer to.
     */
    public function initiate(CreatePaymentRequest $request): PaymentInitiation
    {
        $provider = $this->providerRegistry->getActive();
        $initiation = $provider->createPayment($request);

        $transaction = new PaymentTransaction(
            providerId: $provider->getId(),
            providerPaymentId: $initiation->providerPaymentId,
            externalReference: $request->externalReference,
            amount: $request->amount,
            currency: $request->currency,
            purpose: $request->purpose,
            intent: $request->intent,
            status: PaymentStatus::PENDING,
        );

        if (null !== $request->returnUrl) {
            $transaction->setMetadata(['returnUrl' => $request->returnUrl]);
        }

        $this->em->persist($transaction);
        $this->em->flush();

        return $initiation;
    }

    /**
     * Pull the current status from the provider and persist any change.
     * Dispatches a domain event when the status transitions.
     */
    public function syncStatus(int $transactionId): PaymentStatus
    {
        $transaction = $this->transactionRepository->find($transactionId);
        if (!$transaction instanceof PaymentTransaction) {
            throw new \InvalidArgumentException(sprintf('PaymentTransaction #%d not found.', $transactionId));
        }

        return $this->syncTransaction($transaction);
    }

    public function syncTransaction(PaymentTransaction $transaction): PaymentStatus
    {
        if ($transaction->getStatus()->isTerminal()) {
            return $transaction->getStatus();
        }

        $provider = $this->providerRegistry->get($transaction->getProviderId());

        try {
            $snapshot = $provider->fetchPaymentStatus($transaction->getProviderPaymentId());
        } catch (PaymentProviderException $e) {
            $this->logger->warning('Payment status fetch failed', [
                'transactionId' => $transaction->getId(),
                'providerId' => $transaction->getProviderId(),
                'error' => $e->getMessage(),
            ]);

            return $transaction->getStatus();
        }

        $this->applyStatus($transaction, $snapshot->status);

        return $transaction->getStatus();
    }

    /** @return PaymentTransaction[] */
    public function findPending(int $limit = 200): array
    {
        return $this->transactionRepository->findPending($limit);
    }

    /**
     * Handle an incoming webhook request. Returns true when an event was processed,
     * false when no handler is registered for the given providerId or the event is irrelevant.
     *
     * Currently not exposed via any controller — kept as an extension point.
     */
    public function processWebhook(string $providerId, Request $request): bool
    {
        if (!$this->webhookHandlerRegistry->has($providerId)) {
            return false;
        }

        $handler = $this->webhookHandlerRegistry->get($providerId);
        $event = $handler->handle($request);
        if (null === $event) {
            return false;
        }

        return $this->applyWebhookEvent($providerId, $event);
    }

    private function applyWebhookEvent(string $providerId, NormalizedWebhookEvent $event): bool
    {
        $transaction = $this->transactionRepository->findOneByProviderAndProviderPaymentId(
            $providerId,
            $event->providerPaymentId,
        );
        if (!$transaction instanceof PaymentTransaction) {
            $this->logger->info('Webhook references unknown payment transaction; ignoring', [
                'providerId' => $providerId,
                'providerPaymentId' => $event->providerPaymentId,
            ]);

            return false;
        }

        $newStatus = match ($event->type) {
            WebhookEventType::INITIATED => PaymentStatus::INITIATED,
            WebhookEventType::SETTLED => PaymentStatus::SETTLED,
            WebhookEventType::FAILED => PaymentStatus::FAILED,
            WebhookEventType::CANCELLED => PaymentStatus::CANCELLED,
            WebhookEventType::REFUNDED => PaymentStatus::REFUNDED,
        };

        $this->applyStatus($transaction, $newStatus);

        return true;
    }

    private function applyStatus(PaymentTransaction $transaction, PaymentStatus $newStatus): void
    {
        $previous = $transaction->getStatus();
        if ($previous === $newStatus) {
            return;
        }

        $transaction->setStatus($newStatus);
        $this->em->flush();

        $event = match ($newStatus) {
            PaymentStatus::INITIATED => new PaymentInitiatedEvent($transaction),
            PaymentStatus::SETTLED => new PaymentSettledEvent($transaction),
            PaymentStatus::FAILED => new PaymentFailedEvent($transaction),
            PaymentStatus::CANCELLED => new PaymentCancelledEvent($transaction),
            default => null,
        };

        if (null !== $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
