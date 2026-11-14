<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use App\Service\Calendar\Sync\Ics\IcsFeedClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Verify operator environment configuration reaches the shared feed client. */
final class IcsFeedClientConfigurationTest extends KernelTestCase
{
    #[DataProvider('operatorConfigurations')]
    public function testOperatorAllowListControlsPrivateFeedAccess(?string $value, string $host, bool $allowed): void
    {
        $previousEnv = $_ENV['ICS_FEED_ALLOWLIST'] ?? null;
        $previousServer = $_SERVER['ICS_FEED_ALLOWLIST'] ?? null;

        try {
            if (null === $value) {
                unset($_ENV['ICS_FEED_ALLOWLIST'], $_SERVER['ICS_FEED_ALLOWLIST']);
            } else {
                $_ENV['ICS_FEED_ALLOWLIST'] = $_SERVER['ICS_FEED_ALLOWLIST'] = $value;
            }

            self::bootKernel();
            $transport = new MockHttpClient(new MockResponse('BEGIN:VCALENDAR'));
            self::getContainer()->set(HttpClientInterface::class, $transport);
            $client = self::getContainer()->get(IcsFeedClient::class);

            if ($allowed) {
                self::assertSame('BEGIN:VCALENDAR', $client->fetch('http://'.$host.'/calendar.ics'));
                self::assertSame(1, $transport->getRequestsCount());
            } else {
                try {
                    $client->fetch('http://'.$host.'/calendar.ics');
                    self::fail('Expected an IcsFeedException.');
                } catch (IcsFeedException $exception) {
                    self::assertSame(IcsFeedFailure::Unreachable, $exception->failure);
                }

                self::assertSame(0, $transport->getRequestsCount());
            }
        } finally {
            self::ensureKernelShutdown();
            if (null === $previousEnv) {
                unset($_ENV['ICS_FEED_ALLOWLIST']);
            } else {
                $_ENV['ICS_FEED_ALLOWLIST'] = $previousEnv;
            }
            if (null === $previousServer) {
                unset($_SERVER['ICS_FEED_ALLOWLIST']);
            } else {
                $_SERVER['ICS_FEED_ALLOWLIST'] = $previousServer;
            }
        }
    }

    /** @return iterable<string, array{?string, string, bool}> */
    public static function operatorConfigurations(): iterable
    {
        yield 'unset defaults to blocking' => [null, '192.168.1.20', false];
        yield 'empty blocks private feeds' => ['', '192.168.1.20', false];
        yield 'explicit host' => ['192.168.1.20', '192.168.1.20', true];
        yield 'CSV with whitespace and IPv6' => ['192.168.1.20, fd12:3456:789a::/64', '[fd12:3456:789a::20]', true];
        yield 'unlisted host stays blocked' => ['192.168.1.20', '192.168.1.21', false];
        yield 'metadata stays blocked' => ['192.168.1.0/24', '169.254.169.254', false];
    }
}
