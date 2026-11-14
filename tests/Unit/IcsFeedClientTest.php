<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Verify shared safety limits and failure classification for remote ICS feeds. */
final class IcsFeedClientTest extends TestCase
{
    // Public IP literals avoid DNS lookups; MockHttpClient never sends a real request.
    public function testFetchReturnsSuccessfulBody(): void
    {
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse('BEGIN:VCALENDAR')));

        self::assertSame('BEGIN:VCALENDAR', $client->fetch('https://93.184.216.34/calendar.ics'));
    }

    #[DataProvider('blockedUrls')]
    public function testUnsafeDestinationIsRejectedBeforeTransport(string $url): void
    {
        foreach ([[], ['192.168.1.20', 'fd12:3456:789a::/64']] as $allowList) {
            $transport = new MockHttpClient(new MockResponse('BEGIN:VCALENDAR'));
            $client = new IcsFeedClient($transport, $allowList);

            try {
                $client->fetch($url);
                self::fail('Expected an IcsFeedException.');
            } catch (IcsFeedException $exception) {
                self::assertSame(IcsFeedFailure::Unreachable, $exception->failure);
                self::assertNull($exception->httpStatus);
            }

            self::assertSame(0, $transport->getRequestsCount());
        }
    }

    /** @param list<string> $allowList */
    #[DataProvider('allowedDestinations')]
    public function testOperatorCanAllowSpecificHostsOrNetworks(string $host, array $allowList): void
    {
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse('BEGIN:VCALENDAR')), $allowList);

        self::assertSame('BEGIN:VCALENDAR', $client->fetch('http://'.$host.'/calendar.ics'));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function allowedDestinations(): iterable
    {
        yield 'single IPv4 host' => ['192.168.1.20', ['192.168.1.20']];
        yield 'IPv4 subnet' => ['192.168.1.20', ['192.168.1.0/24']];
        yield 'single IPv6 host' => ['[fd12:3456:789a::20]', ['fd12:3456:789a::20']];
        yield 'IPv6 subnet' => ['[fd12:3456:789a::20]', ['fd12:3456:789a::/64']];
        yield 'local hostname resolves to approved address' => ['localhost', ['127.0.0.1', '::1']];
        yield 'whitespace and empty entries' => ['192.168.1.20', ['', ' 192.168.1.20 ', ' ']];
        yield 'public hosts remain reachable' => ['93.184.216.34', ['192.168.1.20']];
    }

    #[DataProvider('invalidAllowListEntries')]
    public function testInvalidOperatorConfigurationIsRejected(string $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ICS_FEED_ALLOWLIST');

        new IcsFeedClient(new MockHttpClient(), [$entry]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAllowListEntries(): iterable
    {
        yield 'wildcard' => ['*'];
        yield 'hostname' => ['nas.local'];
        yield 'URL' => ['http://192.168.1.20/calendar.ics'];
        yield 'IPv4 port' => ['192.168.1.20:8080'];
        yield 'IPv4 prefix too large' => ['192.168.1.0/33'];
        yield 'IPv6 prefix too large' => ['fd12:3456:789a::/129'];
        yield 'negative prefix' => ['192.168.1.0/-1'];
        yield 'non-numeric prefix' => ['192.168.1.0/abc'];
        yield 'missing prefix' => ['192.168.1.0/'];
        yield 'multiple prefixes' => ['192.168.1.0/24/24'];
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:8080/calendar.ics'];
        yield 'loopback range' => ['http://127.1.2.3/calendar.ics'];
        yield 'local hostname' => ['http://localhost/calendar.ics'];
        yield 'private class A' => ['http://10.0.0.1/calendar.ics'];
        yield 'private class B' => ['http://172.16.0.1/calendar.ics'];
        yield 'private class C' => ['https://192.168.1.1/calendar.ics'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'shared address space' => ['http://100.64.0.1/calendar.ics'];
        yield 'unspecified IPv4' => ['http://0.0.0.0/calendar.ics'];
        yield 'IPv6 loopback' => ['http://[::1]/calendar.ics'];
        yield 'IPv6 private' => ['http://[fd00::1]/calendar.ics'];
        yield 'IPv6 link local' => ['http://[fe80::1]/calendar.ics'];
        yield 'IPv4 mapped IPv6' => ['http://[::ffff:127.0.0.1]/calendar.ics'];
        yield 'IPv4 mapped metadata' => ['http://[::ffff:169.254.169.254]/calendar.ics'];
        yield 'short IPv4' => ['http://127.1/calendar.ics'];
        yield 'decimal IPv4' => ['http://2130706433/calendar.ics'];
        yield 'hexadecimal IPv4' => ['http://0x7f000001/calendar.ics'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'FTP scheme' => ['ftp://93.184.216.34/calendar.ics'];
    }

    #[DataProvider('redirectStatuses')]
    public function testRedirectsWithinLimitAreFollowed(int $status): void
    {
        $transport = new MockHttpClient([
            self::redirect($status, 'https://93.184.216.34/first.ics'),
            self::redirect($status, 'https://93.184.216.35/second.ics'),
            new MockResponse('BEGIN:VCALENDAR'),
        ]);
        $client = new IcsFeedClient($transport);

        self::assertSame('BEGIN:VCALENDAR', $client->fetch('https://93.184.216.34/calendar.ics'));
        self::assertSame(IcsFeedClient::MAX_REDIRECTS + 1, $transport->getRequestsCount());
    }

    #[DataProvider('redirectStatuses')]
    public function testRedirectToPrivateTargetIsRejectedWithoutRequestingIt(int $status): void
    {
        $transport = new MockHttpClient([
            self::redirect($status, 'http://169.254.169.254/latest/meta-data/'),
            new MockResponse('BEGIN:VCALENDAR'),
        ]);
        // Only the listed NAS is approved; the metadata address stays blocked on every hop.
        $client = new IcsFeedClient($transport, ['192.168.1.20']);

        try {
            $client->fetch('https://93.184.216.34/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::Unreachable, $exception->failure);
        }

        self::assertSame(1, $transport->getRequestsCount());
    }

    public function testRedirectChainLongerThanLimitIsRejected(): void
    {
        $transport = new MockHttpClient([
            self::redirect(302, 'https://93.184.216.34/first.ics'),
            self::redirect(302, 'https://93.184.216.34/second.ics'),
            self::redirect(302, 'https://93.184.216.34/third.ics'),
            new MockResponse('BEGIN:VCALENDAR'),
        ]);
        $client = new IcsFeedClient($transport);

        try {
            $client->fetch('https://93.184.216.34/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::Redirect, $exception->failure);
            self::assertSame(302, $exception->httpStatus);
        }

        self::assertSame(IcsFeedClient::MAX_REDIRECTS + 1, $transport->getRequestsCount());
    }

    public function testRedirectWithoutTargetIsReportedAsRedirect(): void
    {
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse('', ['http_code' => 302])));

        try {
            $client->fetch('https://93.184.216.34/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::Redirect, $exception->failure);
            self::assertSame(302, $exception->httpStatus);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function redirectStatuses(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield (string) $status => [$status];
        }
    }

    public function testFeedRequestsBypassProxiesAndKeepResourceLimits(): void
    {
        $response = new MockResponse('BEGIN:VCALENDAR');
        $client = new IcsFeedClient(new MockHttpClient($response));

        $client->fetch('https://93.184.216.34/calendar.ics');

        $options = $response->getRequestOptions();
        self::assertSame('*', $options['no_proxy']);
        // The guard follows redirects itself; the transport must never follow an unchecked hop.
        self::assertSame(0, $options['max_redirects']);
        self::assertEquals(10, $options['timeout']);
        self::assertEquals(10, $options['max_duration']);
    }

    public function testPrivateConnectedAddressIsRejected(): void
    {
        $transport = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            // Simulate a transport reporting a private address for an otherwise public URL.
            $options['on_progress'](0, 0, ['primary_ip' => '10.0.0.1', 'url' => $url]);

            return new MockResponse('BEGIN:VCALENDAR');
        });
        $client = new IcsFeedClient($transport);

        try {
            $client->fetch('https://93.184.216.34/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::Unreachable, $exception->failure);
        }
    }

    public function testNonSuccessfulStatusIsClassified(): void
    {
        $client = new IcsFeedClient(new MockHttpClient(new MockResponse('', ['http_code' => 503])));

        try {
            $client->fetch('https://93.184.216.34/calendar.ics');
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
            $client->fetch('https://93.184.216.34/calendar.ics');
            self::fail('Expected an IcsFeedException.');
        } catch (IcsFeedException $exception) {
            self::assertSame(IcsFeedFailure::TooLarge, $exception->failure);
        }
    }

    /** Build a redirect response the way a real transport reports it to the network guard. */
    private static function redirect(int $status, string $target): MockResponse
    {
        return new MockResponse('', [
            'http_code' => $status,
            'response_headers' => ['Location: '.$target],
            'redirect_url' => $target,
        ]);
    }
}
