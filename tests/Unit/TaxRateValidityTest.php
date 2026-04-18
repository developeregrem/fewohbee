<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\TaxRate;
use PHPUnit\Framework\TestCase;

final class TaxRateValidityTest extends TestCase
{
    public function testIsValidAtWithNoDateBounds(): void
    {
        $taxRate = new TaxRate();

        self::assertTrue($taxRate->isValidAt(new \DateTime('2020-01-01')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2030-12-31')));
    }

    public function testIsValidAtWithOnlyValidFrom(): void
    {
        $taxRate = new TaxRate();
        $taxRate->setValidFrom(new \DateTime('2024-01-01'));

        self::assertFalse($taxRate->isValidAt(new \DateTime('2023-12-31')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2024-01-01')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2025-06-15')));
    }

    public function testIsValidAtWithOnlyValidTo(): void
    {
        $taxRate = new TaxRate();
        $taxRate->setValidTo(new \DateTime('2024-12-31'));

        self::assertTrue($taxRate->isValidAt(new \DateTime('2020-01-01')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2024-12-31')));
        self::assertFalse($taxRate->isValidAt(new \DateTime('2025-01-01')));
    }

    public function testIsValidAtWithBothBounds(): void
    {
        $taxRate = new TaxRate();
        $taxRate->setValidFrom(new \DateTime('2024-01-01'));
        $taxRate->setValidTo(new \DateTime('2024-12-31'));

        self::assertFalse($taxRate->isValidAt(new \DateTime('2023-12-31')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2024-01-01')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2024-06-15')));
        self::assertTrue($taxRate->isValidAt(new \DateTime('2024-12-31')));
        self::assertFalse($taxRate->isValidAt(new \DateTime('2025-01-01')));
    }
}
