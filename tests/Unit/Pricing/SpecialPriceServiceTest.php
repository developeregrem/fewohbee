<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Dto\Pricing\SpecialPriceRequest;
use App\Entity\Price;
use App\Entity\PricePeriod;
use App\Exception\SpecialPriceException;
use App\Repository\PricePeriodRepository;
use App\Repository\PriceRepository;
use App\Repository\ReservationRepository;
use App\Service\Pricing\SpecialPriceService;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecialPriceServiceTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: string}> $expected
     */
    #[DataProvider('cutCases')]
    public function testCutKeepsWhatLiesOutsideTheNewPeriod(string $start, string $end, array $expected): void
    {
        $remaining = SpecialPriceService::cut(
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable('2027-07-10'),
            new \DateTimeImmutable('2027-07-12'),
        );

        self::assertSame($expected, array_map(
            static fn (array $range): array => [$range[0]->format('Y-m-d'), $range[1]->format('Y-m-d')],
            $remaining,
        ));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: list<array{0: string, 1: string}>}>
     */
    public static function cutCases(): iterable
    {
        yield 'covered completely' => ['2027-07-10', '2027-07-12', []];
        yield 'starts before' => ['2027-07-01', '2027-07-11', [['2027-07-01', '2027-07-09']]];
        yield 'ends after' => ['2027-07-11', '2027-07-20', [['2027-07-13', '2027-07-20']]];
        yield 'surrounds' => ['2027-07-01', '2027-07-31', [['2027-07-01', '2027-07-09'], ['2027-07-13', '2027-07-31']]];
    }

    public function testRefusesPastNights(): void
    {
        $this->expectException(SpecialPriceException::class);
        $this->expectExceptionMessage('future nights');

        $this->service($this->price(allYear: true))->plan($this->request('2027-07-01', '2027-07-03', 90.0), new \DateTimeImmutable('2027-07-02'));
    }

    public function testRefusesAddingAPeriodToAYearRoundRow(): void
    {
        $this->expectException(SpecialPriceException::class);
        $this->expectExceptionMessage('applies all year');

        $this->service($this->price(allYear: true))->plan($this->request('2027-07-10', '2027-07-12'), new \DateTimeImmutable('2027-06-01'));
    }

    public function testRefusesAPeriodOverlappingOneOfTheRowItself(): void
    {
        $price = $this->price(allYear: false);
        $price->addPricePeriod((new PricePeriod())->setStart(new \DateTime('2027-07-12'))->setEnd(new \DateTime('2027-07-14')));

        $this->expectException(SpecialPriceException::class);
        $this->expectExceptionMessage('2027-07-12 to 2027-07-14');

        $this->service($price)->plan($this->request('2027-07-10', '2027-07-12'), new \DateTimeImmutable('2027-06-01'));
    }

    public function testRefusesPeriodsBeyondTheLimit(): void
    {
        $this->expectException(SpecialPriceException::class);
        $this->expectExceptionMessage('366 nights');

        $this->service($this->price(allYear: true))->plan($this->request('2027-01-01', '2028-01-02', 90.0), new \DateTimeImmutable('2026-12-01'));
    }

    public function testPlansANewRowWithoutConflicts(): void
    {
        $plan = $this->service($this->price(allYear: true))->plan($this->request('2027-07-10', '2027-07-12', 90.0), new \DateTimeImmutable('2027-06-01'));

        self::assertSame([], $plan->conflicts);
        self::assertTrue($plan->canApply());
        self::assertSame('Double room – Trade fair', (new SpecialPriceService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PriceRepository::class),
            $this->createStub(PricePeriodRepository::class),
            $this->createStub(ReservationRepository::class),
            $this->createStub(PriceService::class),
        ))->rowDescription($plan));
    }

    public function testRowsWithAnotherMinimumStayAreListedButDoNotConflict(): void
    {
        $shortStays = $this->price(allYear: false);
        $shortStays->setId(18);
        $prices = $this->createStub(PriceRepository::class);
        $prices->method('find')->willReturn($this->price(allYear: true));
        $prices->method('findConflictingPriceIdsForPeriod')->willReturn([]);
        $prices->method('findPriceIdsForOtherStayLengthsForPeriod')->willReturn([18]);
        $prices->method('findBy')->willReturn([$shortStays]);

        $plan = $this->service($this->price(allYear: true), $prices)->plan($this->request('2027-07-10', '2027-07-12', 90.0), new \DateTimeImmutable('2027-06-01'));

        self::assertSame([], $plan->conflicts);
        self::assertTrue($plan->canApply());
        self::assertSame([$shortStays], $plan->otherStayLengths);
    }

    private function service(Price $price, ?PriceRepository $prices = null): SpecialPriceService
    {
        if (null === $prices) {
            $prices = $this->createStub(PriceRepository::class);
            $prices->method('find')->willReturn($price);
            $prices->method('findConflictingPriceIdsForPeriod')->willReturn([]);
        }
        $periods = $this->createStub(PricePeriodRepository::class);
        $periods->method('findOverlappingForPrices')->willReturn([]);
        $reservations = $this->createStub(ReservationRepository::class);
        $reservations->method('findUninvoicedForPriceChange')->willReturn([]);

        return new SpecialPriceService(
            $this->createStub(EntityManagerInterface::class),
            $prices,
            $periods,
            $reservations,
            $this->createStub(PriceService::class),
        );
    }

    private function price(bool $allYear): Price
    {
        $price = new Price();
        $price->setId(5);
        $price->setType(2);
        $price->setActive(true);
        $price->setDescription('Double room');
        $price->setPrice(80);
        $price->setAllPeriods($allYear);

        return $price;
    }

    private function request(string $firstNight, string $lastNight, ?float $amount = null): SpecialPriceRequest
    {
        return new SpecialPriceRequest(5, new \DateTimeImmutable($firstNight), new \DateTimeImmutable($lastNight), 'Trade fair', $amount);
    }
}
