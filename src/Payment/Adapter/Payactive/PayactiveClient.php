<?php

declare(strict_types=1);

namespace App\Payment\Adapter\Payactive;

use App\Payment\Exception\PaymentProviderException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Thin HTTP wrapper around the Payactive REST API.
 * Intentionally minimal — only exposes the calls the provider adapter needs.
 *
 * Docs: https://apidocs.payactive.eu/
 */
class PayactiveClient
{
    private const DEFAULT_TIMEOUT = 10;

    private LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create a customer. Returns the customer id.
     *
     * @param array<string, mixed> $payload
     */
    public function createCustomer(array $payload): string
    {
        $data = $this->requestJson('POST', '/customers', $payload);
        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new PaymentProviderException('Payactive: createCustomer response missing "id".');
        }

        return $id;
    }

    /**
     * Create a payment. Returns the payment id.
     *
     * @param array<string, mixed> $payload
     */
    public function createPayment(array $payload): string
    {
        $data = $this->requestJson('POST', '/payments', $payload);
        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new PaymentProviderException('Payactive: createPayment response missing "id".');
        }

        return $id;
    }

    public function getPaymentLink(string $paymentId): ?string
    {
        $data = $this->requestJson('GET', '/payments/'.rawurlencode($paymentId).'/payment-link');
        $link = $data['paymentLink'] ?? null;

        return is_string($link) && '' !== $link ? $link : null;
    }

    /** @return array<string, mixed> */
    public function getPayment(string $paymentId): array
    {
        return $this->requestJson('GET', '/payments/'.rawurlencode($paymentId));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, ?array $body = null): array
    {
        if ('' === $this->apiKey) {
            throw new PaymentProviderException('Payactive: PAYACTIVE_API_KEY is not configured.');
        }

        $options = [
            'headers' => [
                'api_key' => $this->apiKey,
                'Accept' => 'application/json',
            ],
            'timeout' => self::DEFAULT_TIMEOUT,
        ];
        if (null !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, rtrim($this->baseUrl, '/').$path, $options);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->logger->error('Payactive request transport error', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            throw new PaymentProviderException(sprintf('Payactive: transport error on %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            $bodyText = $this->safeBody($response);
            $this->logger->warning('Payactive HTTP error', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'body' => $bodyText,
            ]);
            throw new PaymentProviderException(sprintf('Payactive: HTTP %d on %s %s. Body: %s', $status, $method, $path, $bodyText));
        }

        try {
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new PaymentProviderException(sprintf('Payactive: invalid JSON on %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        return $data;
    }

    private function safeBody(ResponseInterface $response): string
    {
        try {
            return mb_substr($response->getContent(false), 0, 500);
        } catch (ExceptionInterface) {
            return '';
        }
    }
}
