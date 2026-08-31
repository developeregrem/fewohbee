<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Ics;

use App\Exception\IcsFeedException;
use App\Exception\IcsFeedFailure;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads untrusted ICS feeds with shared timeout and response-size limits.
 */
final class IcsFeedClient
{
    public const MAX_RESPONSE_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
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
            ]);
            $status = $response->getStatusCode();
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
