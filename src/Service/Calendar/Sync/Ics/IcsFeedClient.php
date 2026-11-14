<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Ics;

use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads untrusted ICS feeds from public or operator-approved networks; redirect hops get the same check.
 */
final class IcsFeedClient
{
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;
    public const MAX_REDIRECTS = 2;

    private readonly HttpClientInterface $httpClient;

    /** @param list<string> $allowList Operator-controlled IP addresses or CIDR subnets, never user input. */
    public function __construct(HttpClientInterface $httpClient, array $allowList = [])
    {
        $allowList = array_values(array_filter(array_map(trim(...), $allowList), static fn (string $entry): bool => '' !== $entry));
        foreach ($allowList as $entry) {
            [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            if (false === filter_var($ip, FILTER_VALIDATE_IP)
                || (null !== $prefix && (!ctype_digit($prefix) || (int) $prefix > (str_contains($ip, ':') ? 128 : 32)))
            ) {
                throw new \InvalidArgumentException('ICS_FEED_ALLOWLIST must contain only IP addresses or CIDR subnets.');
            }
        }

        // Keep the network boundary shared by preview and every stored-feed import.
        $this->httpClient = new NoPrivateNetworkHttpClient($httpClient, allowList: $allowList);
    }

    /**
     * Fetch a feed while preventing slow or oversized sources from blocking synchronization.
     *
     * @throws IcsFeedException
     */
    public function fetch(string $url): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_duration' => 10,
                // The network guard follows redirects itself and checks every hop.
                'max_redirects' => self::MAX_REDIRECTS,
                // A proxy can resolve a different destination outside the network guard.
                'no_proxy' => '*',
            ]);
            $status = $response->getStatusCode();
            if (300 <= $status && $status < 400) {
                throw new IcsFeedException(IcsFeedFailure::Redirect, $status);
            }
            if (200 !== $status) {
                throw new IcsFeedException(IcsFeedFailure::HttpStatus, $status);
            }

            $content = '';
            foreach ($this->httpClient->stream($response, 10) as $chunk) {
                if ($chunk->isTimeout()) {
                    throw new IcsFeedException(IcsFeedFailure::Unreachable);
                }

                $content .= $chunk->getContent();
                if (strlen($content) > self::MAX_RESPONSE_BYTES) {
                    throw new IcsFeedException(IcsFeedFailure::TooLarge);
                }
            }

            return $content;
        } catch (IcsFeedException $exception) {
            throw $exception;
        } catch (ExceptionInterface $exception) {
            throw new IcsFeedException(IcsFeedFailure::Unreachable, previous: $exception);
        }
    }
}
