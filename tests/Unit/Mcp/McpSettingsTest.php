<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mcp;

use App\Entity\AppSettings;
use App\Service\AppSettingsService;
use App\Service\Mcp\McpSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpSettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?string}>
     */
    public static function hostEntries(): iterable
    {
        yield 'plain' => ['fewohbee.example.com', 'fewohbee.example.com'];
        yield 'upper case' => ['FeWoHBee.Example.COM', 'fewohbee.example.com'];
        yield 'url' => ['https://fewohbee.example.com/mcp', 'fewohbee.example.com'];
        yield 'path without scheme' => ['fewohbee.example.com/mcp', 'fewohbee.example.com'];
        yield 'port' => ['fewohbee.local:8443', 'fewohbee.local'];
        yield 'single label' => ['raspberrypi', 'raspberrypi'];
        yield 'ipv4' => ['192.168.1.20', '192.168.1.20'];
        yield 'ipv6' => ['[fd00::1]:443', '[fd00::1]'];
        yield 'trailing dot' => ['fewohbee.example.com.', 'fewohbee.example.com'];
        yield 'invalid characters' => ['fewo bee!', null];
        yield 'underscore' => ['fewo_bee.example', null];
        yield 'wildcard' => ['*.example.com', null];
        yield 'broken ipv6' => ['[zz::1]', null];
    }

    #[DataProvider('hostEntries')]
    public function testNormalizesHostEntries(string $entry, ?string $expected): void
    {
        self::assertSame($expected, McpSettings::normalizeHost($entry));
    }

    public function testParsesListAndLeavesOutLoopbackAndDuplicates(): void
    {
        $result = McpSettings::parseHosts("fewohbee.example.com\nhttps://FEWOHBEE.example.com/, localhost 127.0.0.1\n\nbad_host!");

        self::assertSame(['fewohbee.example.com'], $result['hosts']);
        self::assertSame(['bad_host!'], $result['invalid']);
    }

    public function testAllowsLoopbackPlusConfiguredHosts(): void
    {
        $appSettings = new AppSettings();
        $appSettings->setMcpAllowedHosts(['fewohbee.example.com']);
        $service = $this->createStub(AppSettingsService::class);
        $service->method('getSettings')->willReturn($appSettings);

        $settings = new McpSettings($service, true, false);

        self::assertSame(['localhost', '127.0.0.1', '[::1]', 'fewohbee.example.com'], $settings->getAllowedHosts());
        self::assertTrue($settings->isHostAllowed('FEWOHBEE.example.com'));
        self::assertFalse($settings->isHostAllowed('buchen.fewohbee.example.com'));
    }
}
