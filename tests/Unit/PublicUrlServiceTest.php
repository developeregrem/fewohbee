<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use App\Service\PublicUrlService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class PublicUrlServiceTest extends TestCase
{
    public function testEnvironmentValueWinsOverTheSetting(): void
    {
        $service = $this->service('https://settings.example.com', 'https://hosting.example.com/', 'https://default.example.com');

        self::assertTrue($service->isPresetByEnvironment());
        self::assertSame('https://hosting.example.com', $service->getBaseUrl());
    }

    public function testSettingIsUsedWithoutEnvironmentValue(): void
    {
        $service = $this->service('https://settings.example.com', null, 'https://default.example.com');

        self::assertFalse($service->isPresetByEnvironment());
        self::assertSame('https://settings.example.com', $service->getBaseUrl());
    }

    public function testDefaultUriIsTheLastFallback(): void
    {
        self::assertSame('https://default.example.com', $this->service(null, '', 'https://default.example.com')->getBaseUrl());
    }

    public function testLocalDefaultUriIsNeverUsedForGuestLinks(): void
    {
        $service = $this->service(null, null, 'http://localhost');

        self::assertNull($service->getBaseUrl());
        self::assertNull($service->generate('public.guest_checkin'));
    }

    public function testGenerateAppendsTheRoutePathToTheBaseUrl(): void
    {
        $service = $this->service('https://example.com/fewohbee', null, null, '/checkin/abc');

        self::assertSame('https://example.com/fewohbee/checkin/abc', $service->generate('public.guest_checkin'));
    }

    public function testGenerateStripsTheBasePathOfTheCurrentRequest(): void
    {
        // Requested through https://intranet/fewohbee: the router prefixes "/fewohbee" already
        // contained in the configured address.
        $service = $this->service('https://example.com/fewohbee', null, null, '/fewohbee/checkin/abc', '/fewohbee');

        self::assertSame('https://example.com/fewohbee/checkin/abc', $service->generate('public.guest_checkin'));
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(?string $input, ?string $expected): void
    {
        self::assertSame($expected, PublicUrlService::normalize($input));
    }

    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function normalizeCases(): iterable
    {
        yield 'trailing slash' => ['https://Example.com/', 'https://example.com'];
        yield 'port and path' => ['http://example.com:8080/sub/', 'http://example.com:8080/sub'];
        yield 'empty' => ['  ', null];
        yield 'null' => [null, null];
        yield 'no scheme' => ['example.com', null];
        yield 'other scheme' => ['ftp://example.com', null];
        yield 'credentials' => ['https://user:pass@example.com', null];
        yield 'query' => ['https://example.com/?a=1', null];
        yield 'fragment' => ['https://example.com/#top', null];
    }

    #[DataProvider('reachabilityCases')]
    public function testReachabilityWarning(string $url, ?string $expected): void
    {
        self::assertSame($expected, PublicUrlService::reachabilityWarning($url));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function reachabilityCases(): iterable
    {
        yield 'public https' => ['https://fewohbee.example.com', null];
        yield 'public http' => ['http://fewohbee.example.com', 'insecure'];
        yield 'localhost' => ['http://localhost:8000', 'local'];
        yield 'mdns' => ['https://pension.local', 'local'];
        yield 'single label' => ['https://server', 'local'];
        yield 'private ip' => ['https://192.168.1.20', 'local'];
        yield 'public ip' => ['https://93.184.215.14', null];
    }

    private function service(
        ?string $setting,
        ?string $environment,
        ?string $defaultUri,
        string $generatedPath = '/checkin/abc',
        string $contextBaseUrl = '',
    ): PublicUrlService {
        $settingsService = $this->createStub(AppSettingsService::class);
        $settingsService->method('getSettings')->willReturn((new AppSettings())->setPublicBaseUrl($setting));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn($generatedPath);
        $urlGenerator->method('getContext')->willReturn(new RequestContext($contextBaseUrl));

        return new PublicUrlService($settingsService, $urlGenerator, $environment, $defaultUri);
    }
}
