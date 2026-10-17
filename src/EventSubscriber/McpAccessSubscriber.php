<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Mcp\McpSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gatekeeper for the MCP endpoint. Before the firewall:
 *
 * - 404 while MCP is not switched on (operator and administrator), even for unauthenticated
 *   requests, so a disabled installation does not reveal the endpoint;
 * - rejects hosts and browser origins outside the allowed hosts (DNS rebinding); the SDK repeats
 *   this check behind the firewall;
 * - requires HTTPS because the bearer token travels with every request. Loopback clients and
 *   installations that set MCP_ALLOW_INSECURE_HTTP=true (LAN without TLS) are exempt;
 * - after authentication, refuses subscriptions/listen right away. The server sends no
 *   notifications, and the SDK would otherwise hold the stream open for its whole lifetime,
 *   blocking one of the few PHP-FPM workers per connected client.
 */
final class McpAccessSubscriber implements EventSubscriberInterface
{
    private const LOOPBACK = ['127.0.0.0/8', '::1'];
    private const LISTEN_METHOD = 'subscriptions/listen';

    public function __construct(
        private readonly McpSettings $mcpSettings,
        #[Autowire(service: 'monolog.logger.mcp')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Firewall listener runs at priority 8, routing at 32.
        return [KernelEvents::REQUEST => [
            ['onKernelRequest', 9],
            ['refuseSubscriptions', 7],
        ]];
    }

    public static function isMcpPath(string $pathInfo): bool
    {
        return '/mcp' === $pathInfo || str_starts_with($pathInfo, '/mcp/');
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!self::isMcpPath($request->getPathInfo())) {
            return;
        }

        if (!$this->mcpSettings->isActive()) {
            $event->setResponse(self::error(Response::HTTP_NOT_FOUND, 'Not found.'));

            return;
        }

        if (!$this->mcpSettings->isHostAllowed($request->getHost()) || !$this->isOriginAllowed($request)) {
            $this->logger->warning('MCP request rejected: host "{host}" or origin is not an allowed address (Settings > AI assistants).', [
                'host' => $request->getHost(),
            ]);
            $event->setResponse(self::error(Response::HTTP_FORBIDDEN, 'Host not allowed.'));

            return;
        }

        if (!$request->isSecure() && !$this->mcpSettings->isInsecureHttpAllowed() && !self::isLoopback($request)) {
            $event->setResponse(self::error(Response::HTTP_FORBIDDEN, 'The MCP endpoint requires HTTPS.'));
        }
    }

    public function refuseSubscriptions(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'POST' !== $request->getMethod() || !self::isMcpPath($request->getPathInfo())) {
            return;
        }

        $method = $request->headers->get('Mcp-Method');
        $message = json_decode((string) $request->getContent(), true);
        if (\is_array($message) && \is_string($message['method'] ?? null)) {
            $method = $message['method'];
        }
        if (self::LISTEN_METHOD !== $method) {
            return;
        }

        // Same answer the SDK gives for a method it does not serve in the stateless revision.
        $id = \is_array($message) && (\is_string($message['id'] ?? null) || \is_int($message['id'] ?? null)) ? $message['id'] : null;
        $event->setResponse(new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'This server sends no notifications; subscriptions/listen is not supported.'],
        ], Response::HTTP_NOT_FOUND));
    }

    private function isOriginAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        if (null === $origin || '' === $origin) {
            return true;
        }

        $host = parse_url($origin, \PHP_URL_HOST);

        return \is_string($host) && $this->mcpSettings->isHostAllowed($host);
    }

    private static function isLoopback(Request $request): bool
    {
        $ip = $request->getClientIp();

        return null !== $ip && IpUtils::checkIp($ip, self::LOOPBACK);
    }

    private static function error(int $status, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $status, 'message' => $message]], $status);
    }
}
