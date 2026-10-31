<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Price;
use App\Entity\PriceComponent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalValueCompatibilityTest extends TestCase
{
    /** @param class-string<InvoiceAppartment|InvoicePosition|Price|PriceComponent> $class */
    #[DataProvider('decimalFields')]
    public function testDraftsKeepTheirFloatValuesWithoutRounding(string $class, string $field): void
    {
        $entity = new $class();
        $getter = 'get'.ucfirst($field);
        $setter = 'set'.ucfirst($field);

        foreach ([0.0, 7.5, 19.0, 1 / 3, 7.000000000000001, 33.333333333333336, 999999.9999] as $value) {
            $entity->$setter($value);
            self::assertSame($value, $entity->$getter());

            // Invoice drafts already store entities with float values in the session.
            $restored = unserialize(serialize($entity));
            self::assertInstanceOf($class, $restored);
            self::assertSame($value, $restored->$getter());
        }
    }

    public function testNewEntitiesKeepTheirExistingDefaults(): void
    {
        self::assertNull((new Price())->getVat());
        $component = new PriceComponent();
        self::assertSame(0.0, $component->getVat());
        self::assertSame(0.0, $component->getAllocationValue());
    }

    /** @return iterable<string, array{class-string<InvoiceAppartment|InvoicePosition|Price|PriceComponent>, string}> */
    public static function decimalFields(): iterable
    {
        yield 'room VAT' => [InvoiceAppartment::class, 'vat'];
        yield 'miscellaneous VAT' => [InvoicePosition::class, 'vat'];
        yield 'room amount' => [InvoiceAppartment::class, 'price'];
        yield 'miscellaneous amount' => [InvoicePosition::class, 'price'];
        yield 'price VAT' => [Price::class, 'vat'];
        yield 'price amount' => [Price::class, 'price'];
        yield 'component VAT' => [PriceComponent::class, 'vat'];
        yield 'component allocation' => [PriceComponent::class, 'allocationValue'];
    }
}
