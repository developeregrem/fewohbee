<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Reservation;
use App\Exception\InvoiceReservationSelectionException;
use App\Repository\ReservationRepository;
use App\Service\AppSettingsService;
use App\Service\InvoiceService;
use App\Service\InvoiceSumCalculator;
use App\Service\OriginFeeCalculator;
use App\Service\PriceService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceReservationSelectionTest extends TestCase
{
    public function testReturnsTheCompleteSelectionInItsOriginalOrder(): void
    {
        $first = new Reservation();
        $second = new Reservation();
        $stack = $this->stack([ReservationService::SESSION_SELECTED_RESERVATIONS => [2 => 9, 5 => 4]]);

        $result = $this->service([4 => $first, 9 => $second])->getInvoiceReservationsInCreation($stack);

        self::assertSame([$second, $first], $result);
    }

    public function testTheCurrentSelectionTakesPrecedenceOverLegacySessionData(): void
    {
        $current = new Reservation();
        $legacy = new Reservation();
        $stack = $this->stack([
            ReservationService::SESSION_SELECTED_RESERVATIONS => [9],
            'invoiceInCreation' => [4],
        ]);

        self::assertSame([$current], $this->service([4 => $legacy, 9 => $current])->getInvoiceReservationsInCreation($stack));
    }

    public function testLegacySelectionIsStillSupported(): void
    {
        $reservation = new Reservation();
        $stack = $this->stack([
            ReservationService::SESSION_SELECTED_RESERVATIONS => [],
            'invoiceInCreation' => [4],
        ]);

        self::assertSame([$reservation], $this->service([4 => $reservation])->getInvoiceReservationsInCreation($stack));
    }

    public function testAnEmptySelectionRemainsValidForManualInvoices(): void
    {
        self::assertSame([], $this->service([])->getInvoiceReservationsInCreation($this->stack([])));
    }

    #[DataProvider('missingReservations')]
    public function testAMissingReservationRejectsTheWholeSelection(string $key, bool $missingFirst): void
    {
        $stack = $this->stack([$key => $missingFirst ? [99, 4] : [4, 99]]);
        $service = $this->service([4 => new Reservation()]);

        $this->expectException(InvoiceReservationSelectionException::class);
        $this->expectExceptionMessage('invoice.reservations.unavailable');

        $service->getInvoiceReservationsInCreation($stack);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function missingReservations(): iterable
    {
        yield 'current selection, missing first' => [ReservationService::SESSION_SELECTED_RESERVATIONS, true];
        yield 'current selection, missing last' => [ReservationService::SESSION_SELECTED_RESERVATIONS, false];
        yield 'legacy selection, missing first' => ['invoiceInCreation', true];
        yield 'legacy selection, missing last' => ['invoiceInCreation', false];
    }

    /** @param array<int, Reservation> $reservations */
    private function service(array $reservations): InvoiceService
    {
        $repository = $this->createStub(ReservationRepository::class);
        $repository->method('find')->willReturnCallback(static fn ($id): ?Reservation => $reservations[$id] ?? null);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $sums = new InvoiceSumCalculator();

        return new InvoiceService(
            $em,
            $this->createStub(PriceService::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(AppSettingsService::class),
            $sums,
            new OriginFeeCalculator($sums),
        );
    }

    /** @param array<string, array<int, int>> $values */
    private function stack(array $values): RequestStack
    {
        $session = new Session(new MockArraySessionStorage());
        $session->replace($values);
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
