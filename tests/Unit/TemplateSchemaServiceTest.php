<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Reservation;
use App\Entity\Subsidiary;
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

    public function testBuildSchemaKeepsDateTypeForRootVariables(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'paymentDueDate' => ['type' => 'date'],
        ]);

        self::assertSame(['type' => 'date'], $schema['paymentDueDate']);
    }

    public function testBuildSchemaMarksNullableRootDate(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'paymentDueDate' => ['type' => 'date', 'nullable' => true],
        ]);

        self::assertSame(['type' => 'date', 'nullable' => true], $schema['paymentDueDate']);
    }

    public function testBuildSchemaTakesDateNullabilityFromTheColumn(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'reservation' => ['class' => Reservation::class],
        ]);

        $properties = $schema['reservation']['properties'];
        self::assertSame(['type' => 'date'], $properties['startDate']);
        self::assertSame(['type' => 'date', 'nullable' => true], $properties['optionDate']);
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

    public function testBuildSchemaOmitsExplicitlyIgnoredGetters(): void
    {
        $service = new TemplateSchemaService();

        $schema = $service->buildSchema([
            'subsidiary' => ['class' => Subsidiary::class],
        ]);

        self::assertArrayNotHasKey('openingHours', $schema['subsidiary']['properties']);
        self::assertSame('scalar', $schema['subsidiary']['properties']['openingHoursNote']['type']);
    }
}
