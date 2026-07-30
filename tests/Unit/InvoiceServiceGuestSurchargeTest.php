<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The extra a guest paid by booking through a portal rather than directly, split
 * into commission and payment fee, the figures shown to them in the invoice email
 * so a direct booking can be compared.
 */
final class InvoiceServiceGuestSurchargeTest extends TestCase
{
    public function testSplitsCommissionAndPaymentFeeAndSumsThem(): void
    {
        // 12 % and 1.4 % of 115.20 gross = 13.82 and 1.61, matching the two
        // deductions booked for it. Their sum is left to the template.
        $invoice = $this->invoiceWithOrigin('Booking.com', '12.00', '1.40');

        $result = $this->resolve($invoice, 115.20);

        self::assertSame('Booking.com', $result['name']);
        self::assertSame(13.82, $result['commission']);
        self::assertSame(1.61, $result['paymentFee']);
    }

    public function testCountsAnOriginWithOnlyOneOfTheTwoPercentages(): void
    {
        $invoice = $this->invoiceWithOrigin('Fewo-direkt', '15.00', null);

        $result = $this->resolve($invoice, 200.0);

        self::assertSame(30.0, $result['commission']);
        self::assertSame(0.0, $result['paymentFee']);
    }

    public function testReturnsZeroWhenTheOriginHasNeitherPercentage(): void
    {
        $invoice = $this->invoiceWithOrigin('Direktbuchung', null, null);

        $result = $this->resolve($invoice, 115.20);

        self::assertNull($result['name']);
        self::assertSame(0.0, $result['commission']);
        self::assertSame(0.0, $result['paymentFee']);
    }

    public function testReturnsZeroWhenNoReservationCarriesAnOrigin(): void
    {
        $invoice = new Invoice();
        $invoice->addReservation(new Reservation());

        $result = $this->resolve($invoice, 200.0);

        self::assertNull($result['name']);
        self::assertSame(0.0, $result['commission']);
        self::assertSame(0.0, $result['paymentFee']);
    }

    public function testSkipsOriginsWithoutPercentagesAndTakesTheFirstThatHasOne(): void
    {
        $invoice = new Invoice();
        $invoice->addReservation($this->reservationWithOrigin('Direktbuchung', null, null));
        $invoice->addReservation($this->reservationWithOrigin('Booking.com', '12.00', '1.40'));

        $result = $this->resolve($invoice, 100.0);

        self::assertSame('Booking.com', $result['name']);
        self::assertSame(12.0, $result['commission']);
        self::assertSame(1.4, $result['paymentFee']);
    }

    public function testShowsTheRatesTheReservationWasBookedUnder(): void
    {
        // The portal has since raised its commission to 18 %. Showing that to the
        // guest would name a figure the journal never booked - the deduction goes
        // by the 12 % the booking was made under.
        $reservation = $this->reservationWithOrigin('Booking.com', '18.00', '2.50');
        $reservation->setCommissionPercent('12.00');
        $reservation->setPaymentFeePercent('1.40');

        $invoice = new Invoice();
        $invoice->addReservation($reservation);

        $result = $this->resolve($invoice, 115.20);

        self::assertSame(13.82, $result['commission']);
        self::assertSame(1.61, $result['paymentFee']);
    }

    public function testFallsBackToTheOriginWhenTheReservationHasNoRatesPinned(): void
    {
        // Booked before the rates were pinned, or under an origin that carried
        // none at the time: the origin is all there is to go on.
        $reservation = $this->reservationWithOrigin('Booking.com', '12.00', '1.40');
        $reservation->setCommissionPercent(null);
        $reservation->setPaymentFeePercent(null);

        $invoice = new Invoice();
        $invoice->addReservation($reservation);

        $result = $this->resolve($invoice, 115.20);

        self::assertSame(13.82, $result['commission']);
        self::assertSame(1.61, $result['paymentFee']);
    }

    /**
     * @return array{name: ?string, commission: float, paymentFee: float}
     */
    private function resolve(Invoice $invoice, float $brutto): array
    {
        $method = new \ReflectionMethod(InvoiceService::class, 'resolveGuestSurcharge');

        return $method->invoke($this->createService(), $invoice, $brutto);
    }

    private function invoiceWithOrigin(string $name, ?string $commission, ?string $paymentFee): Invoice
    {
        $invoice = new Invoice();
        $invoice->addReservation($this->reservationWithOrigin($name, $commission, $paymentFee));

        return $invoice;
    }

    private function reservationWithOrigin(string $name, ?string $commission, ?string $paymentFee): Reservation
    {
        $origin = new ReservationOrigin();
        $origin->setName($name);
        $origin->setCommissionPercent($commission);
        $origin->setPaymentFeePercent($paymentFee);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        return $reservation;
    }

    private function createService(): InvoiceService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $priceService = $this->createStub(PriceService::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn(new AppSettings());

        return new InvoiceService($em, $priceService, $translator, $appSettingsService, null);
    }
}
