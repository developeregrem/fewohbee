<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\EventSubscriber\McpAccessSubscriber;
use App\Service\AppSettingsService;
use App\Service\Mcp\McpSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class McpAccessSubscriberTest extends TestCase
{
    public function testIgnoresOtherPaths(): void
    {
        $event = $this->dispatch(Request::create('http://example.org/mcpx'), active: false);

        self::assertNull($event->getResponse());
    }

    public function testAnswersNotFoundWhileInactive(): void
    {
        $event = $this->dispatch(Request::create('https://fewohbee.example/mcp', 'POST'), active: false);

        self::assertSame(404, $event->getResponse()?->getStatusCode());
    }

    public function testRejectsHostOutsideAllowlist(): void
    {
        $event = $this->dispatch(Request::create('https://buchen.fewohbee.example/mcp', 'POST'));

        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testRejectsForeignBrowserOrigin(): void
    {
        $request = Request::create('https://fewohbee.example/mcp', 'POST');
        $request->headers->set('Origin', 'https://evil.example');

        self::assertSame(403, $this->dispatch($request)->getResponse()?->getStatusCode());
    }

    public function testAlwaysAllowsLoopbackHostNames(): void
    {
        self::assertNull($this->dispatch(Request::create('http://localhost/mcp', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']))->getResponse());
    }

    public function testRejectsPlainHttpFromRemoteClient(): void
    {
        $request = Request::create('http://fewohbee.example/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']);

        self::assertSame(403, $this->dispatch($request)->getResponse()?->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: Request, 1: bool}>
     */
    public static function passingRequests(): iterable
    {
        yield 'https' => [Request::create('https://fewohbee.example/mcp', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']), false];
        yield 'loopback http' => [Request::create('http://fewohbee.example/mcp', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']), false];
        yield 'lan http when allowed' => [Request::create('http://fewohbee.example/mcp', 'POST', server: ['REMOTE_ADDR' => '192.168.1.20']), true];
    }

    #[DataProvider('passingRequests')]
    public function testLetsValidRequestsThrough(Request $request, bool $insecureAllowed): void
    {
        self::assertNull($this->dispatch($request, insecureAllowed: $insecureAllowed)->getResponse());
    }

    private function dispatch(Request $request, bool $active = true, bool $insecureAllowed = false): RequestEvent
    {
        $appSettings = $this->createStub(AppSettingsService::class);
        $settings = new class($appSettings, $active, $insecureAllowed) extends McpSettings {
            public function __construct(AppSettingsService $appSettings, private readonly bool $active, bool $insecureAllowed)
            {
                parent::__construct($appSettings, true, $insecureAllowed);
            }

            public function isActive(): bool
            {
                return $this->active;
            }

            public function getConfiguredHosts(): array
            {
                return ['fewohbee.example'];
            }
        };

        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new McpAccessSubscriber($settings, new NullLogger()))->onKernelRequest($event);

        return $event;
    }
}
