<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Verify shared safety limits and failure classification for remote ICS feeds. */
final class IcsFeedClientTest extends TestCase
{
    public function testFetchReturnsSuccessfulBody(): void
    {
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse('BEGIN:VCALENDAR')));

        self::assertSame('BEGIN:VCALENDAR', $client->fetch('https://example.test/calendar.ics'));
    }

    public function testNonSuccessfulStatusIsClassified(): void
    {
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        try {
            $client->fetch('https://example.test/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::HttpStatus, $exception->failure);
            self::assertSame(503, $exception->httpStatus);
        }
    }

    public function testOversizedResponseIsRejected(): void
    {
        $content = str_repeat('x', IcsFeedClient::MAX_RESPONSE_BYTES + 1);
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse($content)));

        try {
            $client->fetch('https://example.test/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::TooLarge, $exception->failure);
        }
    }
}
