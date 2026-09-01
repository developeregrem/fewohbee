<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Reservation;
use App\Service\TemplateSchemaService;
use PHPUnit\Framework\TestCase;

final class TemplateSchemaServiceTest extends TestCase
{
    public function testBuildSchemaSupportsScalarAndArrayRoots(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'simpleValue' => ['type' => 'scalar'],
            'rows' => ['type' => 'array'],
        ]);

        self::assertSame('scalar', $schema['simpleValue']['type']);
        self::assertSame('array', $schema['rows']['type']);
        self::assertSame('row', $schema['rows']['singularName']);
    }

    public function testBuildSchemaResolvesEntityAndCollectionMetadata(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'reservation1' => ['class' => Reservation::class],
            'reservations' => ['class' => Reservation::class, 'collection' => true],
        ]);

        self::assertSame('entity', $schema['reservation1']['type']);
        self::assertSame('Reservation', $schema['reservation1']['class']);
        self::assertArrayHasKey('startDate', $schema['reservation1']['properties']);
        self::assertSame('date', $schema['reservation1']['properties']['startDate']['type']);

        self::assertSame('collection', $schema['reservations']['type']);
        self::assertSame('reservation', $schema['reservations']['singularName']);
        self::assertArrayHasKey('invoices', $schema['reservations']['properties']);
        self::assertSame('collection', $schema['reservations']['properties']['invoices']['type']);
    }

    public function testBuildSchemaFallsBackToScalarForInvalidClass(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'broken' => ['class' => 'App\\Entity\\DoesNotExist'],
        ]);

        self::assertSame('scalar', $schema['broken']['type']);
    }

    /**
     * The opening hours form tells the user the times can be used as a placeholder in
     * letter and email templates. That promise only holds while the branch stays within
     * the schema's depth limit, so it is asserted rather than assumed.
     */
    public function testOpeningHoursOfTheBranchAreReachableFromAReservation(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'reservation1' => ['class' => Reservation::class],
        ]);

        $node = $schema['reservation1'];
        foreach (['appartment', 'object', 'openingHoursFormatted'] as $step) {
            self::assertArrayHasKey($step, $node['properties'], 'not reachable: '.$step);
            $node = $node['properties'][$step];
        }

        self::assertSame('scalar', $node['type']);
    }
}

