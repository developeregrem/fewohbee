<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Customer;
use App\Entity\Reservation;
use App\Repository\GuestCategoryRepository;
use App\Service\AvailabilityService;
use App\Service\InvoiceService;
use App\Service\Pricing\PricePromiseService;
use App\Service\ReservationPeriodService;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ReservationCustomerFlashTest extends TestCase
{
    /**
     * @param list<int> $existingCustomerIds
     * @param list<int> $expectedCustomerIds
     * @param array<string, list<string>> $expectedMessages
     */
    #[DataProvider('customerUpdates')]
    public function testCustomerUpdateKeepsFeedbackAndPersistenceBehaviour(
        string $tab,
        int $capacity,
        array $existingCustomerIds,
        int $selectedCustomerId,
        array $expectedCustomerIds,
        array $expectedMessages,
        bool $writesReservation,
    ): void {
        $reservation = new Reservation();
        $reservation->setPersons($capacity);
        foreach ($existingCustomerIds as $id) {
            $reservation->addCustomer($this->makeCustomer($id));
        }
        $customer = $this->makeCustomer($selectedCustomerId);
        $session = new Session(new MockArraySessionStorage());
        $session->getFlashBag()->add('notice', 'existing.message');
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($writesReservation ? self::once() : self::never())
            ->method('persist')->with($reservation);
        $em->expects($writesReservation ? self::once() : self::never())
            ->method('flush');
        $service = new ReservationService(
            $em,
            $requestStack,
            $this->createStub(InvoiceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(GuestCategoryRepository::class),
            $this->createStub(AvailabilityService::class),
            new ReservationPeriodService(),
            $this->createStub(PricePromiseService::class),
        );

        $service->updateReservationCustomers($reservation, $customer, $tab);

        self::assertSame('booker' === $tab ? $customer : null, $reservation->getBooker());
        self::assertSame(
            $expectedCustomerIds,
            array_map(static fn (Customer $guest): ?int => $guest->getId(), $reservation->getCustomers()->toArray()),
        );
        self::assertSame(['notice' => ['existing.message']] + $expectedMessages, $session->getFlashBag()->peekAll());
    }

    /**
     * @return iterable<string, array{string, int, list<int>, int, list<int>, array<string, list<string>>, bool}>
     */
    public static function customerUpdates(): iterable
    {
        yield 'booker saved' => ['booker', 2, [1], 2, [1], ['success' => ['reservation.flash.update.success']], true];
        yield 'guest added' => ['guest', 2, [1], 2, [1, 2], ['success' => ['reservation.flash.update.success']], true];
        yield 'duplicate guest rejected' => ['guest', 2, [1], 1, [1], ['warning' => ['reservation.flash.update.customeralreadyinlist']], false];
        yield 'full guest list rejected' => ['guest', 2, [1, 2], 3, [1, 2], ['warning' => ['reservation.flash.update.toomuchcustomers']], false];
    }

    private function makeCustomer(int $id): Customer
    {
        $customer = $this->createStub(Customer::class);
        $customer->method('getId')->willReturn($id);

        return $customer;
    }
}
