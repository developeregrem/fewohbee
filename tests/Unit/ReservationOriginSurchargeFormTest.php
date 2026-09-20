<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReservationOriginService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The two portal-fee percentages only apply while the origin is flagged as
 * charging them, so an unchecked flag has to clear them whatever the hidden
 * fields still submit.
 */
final class ReservationOriginSurchargeFormTest extends TestCase
{
    public function testKeepsThePercentagesWhenTheFlagIsSet(): void
    {
        $origin = $this->parse([
            'name-new' => 'Booking.com',
            'surcharge-enabled-new' => '1',
            'commission-new' => '12',
            'payment-fee-new' => '1,4',
        ]);

        self::assertSame('12', $origin->getCommissionPercent());
        // The comma a German keyboard produces is normalised to a dot.
        self::assertSame('1.4', $origin->getPaymentFeePercent());
    }

    public function testClearsThePercentagesWhenTheFlagIsMissing(): void
    {
        // The inputs still submit - they are only hidden - but without the flag
        // they must not be stored.
        $origin = $this->parse([
            'name-new' => 'Direktbuchung',
            'commission-new' => '12',
            'payment-fee-new' => '1.4',
        ]);

        self::assertNull($origin->getCommissionPercent());
        self::assertNull($origin->getPaymentFeePercent());
    }

    public function testFlaggedButEmptyFieldsStoreNull(): void
    {
        $origin = $this->parse([
            'name-new' => 'Booking.com',
            'surcharge-enabled-new' => '1',
            'commission-new' => '',
            'payment-fee-new' => '',
        ]);

        self::assertNull($origin->getCommissionPercent());
        self::assertNull($origin->getPaymentFeePercent());
    }

    public function testFlaggedWithoutAnyValueIsRejected(): void
    {
        $request = new Request([], [
            'name-new' => 'Booking.com',
            'surcharge-enabled-new' => '1',
            'commission-new' => '',
            'payment-fee-new' => '',
        ]);
        $origin = $this->service()->getOriginFromForm($request, 'new');

        self::assertSame('reservationorigin.flash.surcharge_required', $this->service()->findSurchargeValueError($request, 'new', $origin));
    }

    public function testFlaggedWithOneValuePasses(): void
    {
        $request = new Request([], [
            'name-new' => 'Booking.com',
            'surcharge-enabled-new' => '1',
            'commission-new' => '12',
            'payment-fee-new' => '',
        ]);
        $origin = $this->service()->getOriginFromForm($request, 'new');

        self::assertNull($this->service()->findSurchargeValueError($request, 'new', $origin));
    }

    public function testUnflaggedIsNeverRejectedEvenWhenEmpty(): void
    {
        $request = new Request([], ['name-new' => 'Direktbuchung']);
        $origin = $this->service()->getOriginFromForm($request, 'new');

        self::assertNull($this->service()->findSurchargeValueError($request, 'new', $origin));
    }

    /**
     * Values the decimal(5,2) column cannot hold, each rejected by name.
     *
     * The form's own min/max/step are a courtesy to whoever types; anything can
     * be posted past them.
     */
    public static function unusablePercentages(): \Generator
    {
        yield 'nicht numerisch' => ['zwölf'];
        yield 'negativ' => ['-5'];
        yield 'über hundert' => ['120'];
        yield 'zu viele Nachkommastellen' => ['12.345'];
        yield 'Ausdruck' => ['12%'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusablePercentages')]
    public function testAnUnusablePercentageIsRejectedAndNeverStored(string $typed): void
    {
        $request = new Request([], [
            'name-new' => 'Booking.com',
            'surcharge-enabled-new' => '1',
            'commission-new' => $typed,
            'payment-fee-new' => '1,4',
        ]);
        $origin = $this->service()->getOriginFromForm($request, 'new');

        self::assertNull($origin->getCommissionPercent(), 'the unusable value reached the entity');
        self::assertSame(
            'reservationorigin.flash.surcharge_invalid',
            $this->service()->findSurchargeValueError($request, 'new', $origin)
        );
    }

    public function testTheBoundsThemselvesArePercentages(): void
    {
        foreach (['0', '100', '12,5', '1.4', '99.99'] as $typed) {
            $request = new Request([], [
                'name-new' => 'Booking.com',
                'surcharge-enabled-new' => '1',
                'commission-new' => $typed,
                'payment-fee-new' => '',
            ]);
            $origin = $this->service()->getOriginFromForm($request, 'new');

            self::assertSame(str_replace(',', '.', $typed), $origin->getCommissionPercent());
            self::assertNull($this->service()->findSurchargeValueError($request, 'new', $origin), $typed);
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function parse(array $params): \App\Entity\ReservationOrigin
    {
        return $this->service()->getOriginFromForm(new Request([], $params), 'new');
    }

    private function service(): ReservationOriginService
    {
        return new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(RequestStack::class),
        );
    }
}
