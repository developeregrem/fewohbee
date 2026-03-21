<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\PublicBookingController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test the occupancy selection parsing logic from POST data.
 */
final class PublicBookingControllerOccupancyParsingTest extends TestCase
{
    /**
     * Use reflection to test the private extractOccupancySelection method directly.
     *
     * @return array<string, array<int, int>>
     */
    private function callExtractOccupancySelection(Request $request): array
    {
        $controller = new PublicBookingController();
        $method = new \ReflectionMethod(PublicBookingController::class, 'extractOccupancySelection');

        return $method->invoke($controller, $request);
    }

    /** Standard occupancy fields should be parsed correctly. */
    public function testParsesStandardOccupancyFields(): void
    {
        $request = new Request([], [
            'occ_category:1_p1' => '0',
            'occ_category:1_p2' => '1',
            'occ_category:2_p1' => '2',
            'intent' => 'preview',
            'dateFrom' => '2026-06-01',
        ]);

        $result = $this->callExtractOccupancySelection($request);

        self::assertSame([
            'category:1' => [1 => 0, 2 => 1],
            'category:2' => [1 => 2],
        ], $result);
    }

    /** Fields without the occ_ prefix should be ignored. */
    public function testIgnoresNonOccupancyFields(): void
    {
        $request = new Request([], [
            'qty_category:1' => '2',
            'dateFrom' => '2026-06-01',
            'occ_category:1_p2' => '1',
        ]);

        $result = $this->callExtractOccupancySelection($request);

        self::assertCount(1, $result);
        self::assertArrayHasKey('category:1', $result);
        self::assertSame([2 => 1], $result['category:1']);
    }

    /** Empty request should return empty array. */
    public function testEmptyRequestReturnsEmpty(): void
    {
        $request = new Request();

        $result = $this->callExtractOccupancySelection($request);

        self::assertSame([], $result);
    }

    /** Apartment-type keys should be parsed correctly too. */
    public function testParsesApartmentTypeKeys(): void
    {
        $request = new Request([], [
            'occ_apartment:42_p3' => '1',
        ]);

        $result = $this->callExtractOccupancySelection($request);

        self::assertSame([
            'apartment:42' => [3 => 1],
        ], $result);
    }

    /** Invalid persons value (0 or negative) should be excluded. */
    public function testExcludesInvalidPersonsValues(): void
    {
        $request = new Request([], [
            'occ_category:1_p0' => '1',
            'occ_category:1_p-1' => '1',
            'occ_category:1_p2' => '1',
        ]);

        $result = $this->callExtractOccupancySelection($request);

        self::assertSame([
            'category:1' => [2 => 1],
        ], $result);
    }
}
