<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\AppSettingsService;
use App\Service\CalendarEntryDisplayService;
use App\Service\CalendarService;
use App\Twig\AppTwigExtensions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Verifies the row-splitting behind the calendar-entry accent bars
 * (calendar_accent_marker_style): up to 4 colors fill one row as equal
 * side-by-side segments; beyond that, colors are distributed across as few
 * additional rows as possible, as evenly as possible.
 */
final class CalendarAccentMarkerStyleTest extends TestCase
{
    private AppTwigExtensions $extensions;

    protected function setUp(): void
    {
        // Stubs, not mocks - the accent-marker logic under test never calls
        // any of these, they only exist to satisfy the constructor.
        $this->extensions = new AppTwigExtensions(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(RequestStack::class),
            $this->createStub(CalendarService::class),
            $this->createStub(AppSettingsService::class),
            $this->createStub(CalendarEntryDisplayService::class),
        );
    }

    public function testNoColorsProducesNoStyle(): void
    {
        self::assertSame('', $this->extensions->getCalendarAccentMarkerStyle([]));
    }

    public function testDuplicateColorsAreCollapsedToOneSegment(): void
    {
        self::assertSame($this->rowSizes(['#fff']), $this->rowSizes(['#fff', '#fff']));
    }

    /**
     * @param string[] $colors
     * @param int[]    $expectedRowSizes
     */
    #[DataProvider('rowSplits')]
    public function testRowSplitting(array $colors, array $expectedRowSizes): void
    {
        self::assertSame($expectedRowSizes, $this->rowSizes($colors));
    }

    /**
     * @return iterable<string, array{0: string[], 1: int[]}>
     */
    public static function rowSplits(): iterable
    {
        $color = static fn (int $n) => \sprintf('#%06d', $n);
        $colors = static fn (int $n) => array_map($color, range(1, $n));

        yield '1 color -> single full-width segment' => [$colors(1), [1]];
        yield '2 colors -> one row, 50/50' => [$colors(2), [2]];
        yield '3 colors -> one row, thirds' => [$colors(3), [3]];
        yield '4 colors -> one row, quarters' => [$colors(4), [4]];
        yield '5 colors -> two rows, 3 then 2' => [$colors(5), [3, 2]];
        yield '6 colors -> two rows, thirds each' => [$colors(6), [3, 3]];
        yield '7 colors -> two rows, 4 then 3' => [$colors(7), [4, 3]];
        yield '8 colors -> two rows, quarters each' => [$colors(8), [4, 4]];
        yield '9 colors -> three rows, thirds each' => [$colors(9), [3, 3, 3]];
    }

    public function testEachRowIsOneGradientLayerWithHardStopsAtEqualPercentages(): void
    {
        $style = $this->extensions->getCalendarAccentMarkerStyle(['#111111', '#222222', '#333333']);

        self::assertStringContainsString(
            'background-image: linear-gradient(to right, #111111 0%, #111111 33%, #222222 33%, #222222 67%, #333333 67%, #333333 100%);',
            $style,
        );
        // A single row means exactly one background-position/-size layer.
        self::assertStringContainsString('background-position: 0 0px;', $style);
        self::assertStringContainsString('background-size: 100% 3px;', $style);
    }

    public function testFiveColorsProduceTwoStackedGradientLayers(): void
    {
        $style = $this->extensions->getCalendarAccentMarkerStyle(['#111111', '#222222', '#333333', '#444444', '#555555']);

        self::assertSame(2, substr_count($style, 'linear-gradient('));
        self::assertStringContainsString('background-position: 0 0px, 0 3px;', $style);
    }

    public function testPreservesTheStickyHeaderBottomBorder(): void
    {
        $style = $this->extensions->getCalendarAccentMarkerStyle(['#123456']);

        self::assertStringContainsString('box-shadow: inset 0 -1px 0 0 var(--bs-border-color);', $style);
    }

    /**
     * Calls the private row-splitting helper directly via reflection - the
     * public method's CSS-string shape is covered separately above, this
     * isolates the row-size math the whole feature is about.
     *
     * @param string[] $colors
     *
     * @return int[]
     */
    private function rowSizes(array $colors): array
    {
        $method = new \ReflectionMethod(AppTwigExtensions::class, 'splitIntoBalancedRows');
        $rows = $method->invoke($this->extensions, array_values(array_unique($colors)), 4);

        return array_map('count', $rows);
    }
}
