<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AppSettings;
use App\Entity\Enum\PaymentCollection;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\Template;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\InvoiceSumCalculator;
use App\Service\OriginFeeCalculator;
use App\Service\PriceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The portal fee placeholders an invoice template can print.
 *
 * They and the journal's deduction come from the same calculator, so the guest
 * is shown what the accounts record. Where the invoice states no single figure
 * the journal refuses to book it - and the placeholders must not print one
 * either, or the two would disagree in exactly the case where it matters.
 */
final class InvoiceServiceOriginPlaceholderTest extends TestCase
{
    public function testStatesTheFeesOfAPlainPortalInvoice(): void
    {
        $invoice = $this->invoice($this->reservation('12.00', '1.40', PaymentCollection::PORTAL));

        $params = $this->service()->buildTemplateRenderParams(new Template(), $invoice);

        self::assertSame('Booking.com', $params['originName']);
        self::assertSame(24.00, $params['originCommission']);
        self::assertSame('24,00', $params['originCommissionFormated']);
        self::assertSame(2.80, $params['originPaymentFee']);
        self::assertSame('2,80', $params['originPaymentFeeFormated']);
    }

    public function testPrintsNoFigureWhereTheReservationsWereBookedAtDifferentRates(): void
    {
        // The journal skips such an invoice. Printing the first reservation's
        // rate across the whole of it would put a number on the invoice that
        // nothing else in the system agrees with.
        $invoice = $this->invoice(
            $this->reservation('12.00', '1.40', PaymentCollection::PORTAL),
            $this->reservation('18.00', '2.50', PaymentCollection::PORTAL),
        );

        $params = $this->service()->buildTemplateRenderParams(new Template(), $invoice);

        self::assertNull($params['originCommission']);
        self::assertSame('', $params['originCommissionFormated']);
        // The portal is still named, so a template can say whose fees these are.
        self::assertSame('Booking.com', $params['originName']);
    }

    public function testPrintsNoPaymentFeeWhereTheStaysWereSettledDifferently(): void
    {
        $invoice = $this->invoice(
            $this->reservation('12.00', '1.40', PaymentCollection::PORTAL),
            $this->reservation('12.00', '1.40', PaymentCollection::PROPERTY),
        );

        $params = $this->service()->buildTemplateRenderParams(new Template(), $invoice);

        self::assertNull($params['originPaymentFee']);
        self::assertSame('', $params['originPaymentFeeFormated']);
    }

    public function testTheCommissionStillPrintsWhereOnlyTheSettlementDiffers(): void
    {
        // Commission is charged on what the portal brokered, which both stays
        // were - who took the money does not come into it.
        $invoice = $this->invoice(
            $this->reservation('12.00', '1.40', PaymentCollection::PORTAL),
            $this->reservation('12.00', '1.40', PaymentCollection::PROPERTY),
        );

        $params = $this->service()->buildTemplateRenderParams(new Template(), $invoice);

        self::assertSame(24.00, $params['originCommission']);
    }

    private function invoice(Reservation ...$reservations): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('T1');
        $invoice->setDate(new \DateTime('2026-06-21'));

        foreach ($reservations as $reservation) {
            $invoice->addReservation($reservation);
        }

        $stay = new InvoiceAppartment();
        $stay->setDescription('Doppelzimmer');
        $stay->setNumber('1');
        $stay->setStartDate(new \DateTime('2026-06-19'));
        $stay->setEndDate(new \DateTime('2026-06-21'));
        $stay->setPersons(2);
        $stay->setBeds(2);
        $stay->setPrice(200.00);
        $stay->setVat(7.0);
        $stay->setIncludesVat(true);
        $stay->setIsFlatPrice(true);
        $invoice->addAppartment($stay);

        return $invoice;
    }

    private function reservation(string $commission, string $paymentFee, PaymentCollection $collection): Reservation
    {
        $origin = new ReservationOrigin();
        $origin->setName('Booking.com');
        $origin->setCommissionPercent($commission);
        $origin->setPaymentFeePercent($paymentFee);
        $origin->setPaymentCollection($collection);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);

        return $reservation;
    }

    private function service(): InvoiceService
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $appSettingsService = $this->createStub(AppSettingsService::class);
        $appSettingsService->method('getSettings')->willReturn(new AppSettings());

        return new InvoiceService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(PriceService::class),
            $translator,
            $appSettingsService,
            new InvoiceSumCalculator(),
            new OriginFeeCalculator(new InvoiceSumCalculator()),
        );
    }
}
