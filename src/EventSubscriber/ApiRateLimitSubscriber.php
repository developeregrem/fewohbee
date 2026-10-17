<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\ApiTokenContext;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttles /api/ and /mcp requests per token (fallback: per client IP), each with its own limiter.
 * runs right after the firewall listener, so the token is known.
 */
class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire(service: 'limiter.api')]
        private readonly RateLimiterFactoryInterface $apiLimiter,
        #[Autowire(service: 'limiter.mcp')]
        private readonly RateLimiterFactoryInterface $mcpLimiter,
        private readonly ApiTokenContext $apiTokenContext,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $limiter = $this->apiLimiter;
        } elseif (McpAccessSubscriber::isMcpPath($request->getPathInfo())) {
            $limiter = $this->mcpLimiter;
        } else {
            return;
        }

        $apiToken = $this->apiTokenContext->getToken();
        $key = null !== $apiToken ? 'token-'.$apiToken->getId() : 'ip-'.($request->getClientIp() ?? 'unknown');
        $limit = $limiter->create($key)->consume();

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $response = new JsonResponse(
                ['error' => ['code' => Response::HTTP_TOO_MANY_REQUESTS, 'message' => 'Too many requests.']],
                Response::HTTP_TOO_MANY_REQUESTS
            );
            $response->headers->set('Retry-After', (string) $retryAfter);
            $response->headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
            $response->headers->set('X-RateLimit-Remaining', (string) $limit->getRemainingTokens());
            $event->setResponse($response);
        }
    }
}
