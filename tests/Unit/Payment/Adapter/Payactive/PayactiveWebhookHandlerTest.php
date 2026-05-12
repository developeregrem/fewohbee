<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment\Adapter\Payactive;

use App\Payment\Adapter\Payactive\PayactiveWebhookHandler;
use App\Payment\Enum\WebhookEventType;
use App\Payment\Exception\WebhookSignatureException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PayactiveWebhookHandlerTest extends TestCase
{
    private const SECRET = 'test_signing_secret';
    private const FIXTURE = __DIR__.'/../../../../Fixtures/Payment/payactive-webhook-settled.json';

    public function testValidSignatureReturnsNormalizedSettledEvent(): void
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        $signature = hash_hmac('sha256', $body, self::SECRET);
        $request = $this->makeRequest($body, $signature);

        $handler = new PayactiveWebhookHandler(self::SECRET);
        $event = $handler->handle($request);

        self::assertNotNull($event);
        self::assertSame(WebhookEventType::SETTLED, $event->type);
        self::assertSame('d6d8d863-d46c-4044-9ff3-68cd67142abd', $event->providerPaymentId);
        self::assertSame(47.6, $event->amount);
    }

    public function testTamperedBodyIsRejected(): void
    {
        $body = (string) file_get_contents(self::FIXTURE);
        $signature = hash_hmac('sha256', $body, self::SECRET);
        $tampered = str_replace('47.6', '99.9', $body);

        $handler = new PayactiveWebhookHandler(self::SECRET);

        $this->expectException(WebhookSignatureException::class);
        $handler->handle($this->makeRequest($tampered, $signature));
    }

    public function testMissingSignatureHeaderIsRejected(): void
    {
        $handler = new PayactiveWebhookHandler(self::SECRET);
        $this->expectException(WebhookSignatureException::class);
        $handler->handle($this->makeRequest('{}', null));
    }

    public function testMissingSecretIsRejected(): void
    {
        $handler = new PayactiveWebhookHandler('');
        $this->expectException(WebhookSignatureException::class);
        $handler->handle($this->makeRequest('{}', 'doesnt-matter'));
    }

    public function testUnknownEventTypeReturnsNull(): void
    {
        $body = json_encode([
            'event_type' => 'checkout.completed',
            'event_data' => ['payment_id' => 'abc'],
        ]);
        self::assertIsString($body);

        $signature = hash_hmac('sha256', $body, self::SECRET);
        $handler = new PayactiveWebhookHandler(self::SECRET);

        self::assertNull($handler->handle($this->makeRequest($body, $signature)));
    }

    private function makeRequest(string $body, ?string $signature): Request
    {
        $server = [];
        if (null !== $signature) {
            $server['HTTP_X_PAYLOAD_SIGNATURE'] = $signature;
        }

        return new Request([], [], [], [], [], $server, $body);
    }
}
