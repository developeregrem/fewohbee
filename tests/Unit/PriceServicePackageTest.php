<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Enum\PriceComponentAllocationType;
use App\Entity\Price;
use App\Entity\PriceComponent;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PriceServicePackageTest extends TestCase
{
    private function makeService(): PriceService
    {
        return new PriceService($this->createStub(EntityManagerInterface::class));
    }

    private function makeComponent(string $desc, float $vat, PriceComponentAllocationType $type, float $value, int $order = 0, bool $remainder = false): PriceComponent
    {
        $c = new PriceComponent();
        $c->setDescription($desc);
        $c->setVat($vat);
        $c->setAllocationType($type);
        $c->setAllocationValue($value);
        $c->setSortOrder($order);
        $c->setIsRemainder($remainder);

        return $c;
    }

    private function makePackagePrice(float $total, array $components): Price
    {
        $p = new Price();
        $p->setDescription('Frühstück');
        $p->setPrice((string) $total);
        foreach ($components as $c) {
            $p->addComponent($c);
        }

        return $p;
    }

    public function testNonPackagePriceValidatesAsValid(): void
    {
        $service = $this->makeService();
        self::assertSame([], $service->validateComponents(new Price()));
    }

    public function testValid70_30PercentSplit(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('Speisen', 7.0, PriceComponentAllocationType::PERCENT, 70.0, 0),
            $this->makeComponent('Getränke', 19.0, PriceComponentAllocationType::PERCENT, 30.0, 1),
        ]);

        self::assertSame([], $this->makeService()->validateComponents($price));
    }

    public function testPercentOverflowIsSumMismatch(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('A', 7.0, PriceComponentAllocationType::PERCENT, 70.0),
            $this->makeComponent('B', 19.0, PriceComponentAllocationType::PERCENT, 40.0),
        ]);

        $errors = $this->makeService()->validateComponents($price);
        self::assertContains('price.package.error.sum_mismatch', $errors);
    }

    public function testMultipleRemainderFlagsError(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('A', 7.0, PriceComponentAllocationType::PERCENT, 0.0, 0, true),
            $this->makeComponent('B', 19.0, PriceComponentAllocationType::PERCENT, 0.0, 1, true),
        ]);

        $errors = $this->makeService()->validateComponents($price);
        self::assertContains('price.package.error.multiple_remainder', $errors);
    }

    public function testMixedAmountAndRemainderValidates(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('Getränke', 19.0, PriceComponentAllocationType::AMOUNT, 4.50, 0),
            $this->makeComponent('Speisen', 7.0, PriceComponentAllocationType::PERCENT, 0.0, 1, true),
        ]);

        self::assertSame([], $this->makeService()->validateComponents($price));
    }

    public function testAmountOverTotalWithRemainderFlags(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('X', 19.0, PriceComponentAllocationType::AMOUNT, 20.00, 0),
            $this->makeComponent('Y', 7.0, PriceComponentAllocationType::PERCENT, 0.0, 1, true),
        ]);

        $errors = $this->makeService()->validateComponents($price);
        self::assertContains('price.package.error.amount_over_total', $errors);
    }

    public function testExpandPackage70_30(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('Speisen', 7.0, PriceComponentAllocationType::PERCENT, 70.0, 0),
            $this->makeComponent('Getränke', 19.0, PriceComponentAllocationType::PERCENT, 30.0, 1),
        ]);

        $expanded = $this->makeService()->expandPackage($price, 15.00, 3, true);
        self::assertCount(2, $expanded);
        self::assertEqualsWithDelta(10.50, $expanded[0]['unitPrice'], 0.001);
        self::assertEqualsWithDelta(4.50, $expanded[1]['unitPrice'], 0.001);
        self::assertSame(3, $expanded[0]['amount']);
        self::assertTrue($expanded[0]['includesVat']);
        self::assertSame('Speisen', $expanded[0]['component']->getDescription());
    }

    public function testExpandPackageRemainderAbsorbsResidue(): void
    {
        $price = $this->makePackagePrice(15.00, [
            $this->makeComponent('Getränke', 19.0, PriceComponentAllocationType::AMOUNT, 4.50, 0),
            $this->makeComponent('Speisen', 7.0, PriceComponentAllocationType::PERCENT, 0.0, 1, true),
        ]);

        $expanded = $this->makeService()->expandPackage($price, 15.00, 1, true);
        self::assertCount(2, $expanded);
        $sum = array_sum(array_column($expanded, 'unitPrice'));
        self::assertEqualsWithDelta(15.00, $sum, 0.001);
        self::assertEqualsWithDelta(10.50, $expanded[1]['unitPrice'], 0.001);
    }

    public function testExpandPackageRoundingResidueGoesToLast(): void
    {
        // Three 33.33 % components of 10.00 → rounding residue of 0.01 should land on the last position
        $price = $this->makePackagePrice(10.00, [
            $this->makeComponent('A', 7.0, PriceComponentAllocationType::PERCENT, 33.33, 0),
            $this->makeComponent('B', 7.0, PriceComponentAllocationType::PERCENT, 33.33, 1),
            $this->makeComponent('C', 7.0, PriceComponentAllocationType::PERCENT, 33.34, 2),
        ]);

        $expanded = $this->makeService()->expandPackage($price, 10.00, 1, true);
        $sum = array_sum(array_column($expanded, 'unitPrice'));
        self::assertEqualsWithDelta(10.00, $sum, 0.001);
    }
}
