<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Service\Reservation\ReservationMailRecipientResolver;
use PHPUnit\Framework\TestCase;

final class ReservationMailRecipientResolverTest extends TestCase
{
    public function testDefaultRecipientIsTheBookersLastEmail(): void
    {
        $reservations = $this->reservations('', 'guest@example.com', null);

        self::assertSame('guest@example.com', (new ReservationMailRecipientResolver())->defaultRecipient($reservations));
    }

    public function testNoDefaultRecipientWithoutBooker(): void
    {
        self::assertSame('', (new ReservationMailRecipientResolver())->defaultRecipient([new Reservation()]));
        self::assertSame('', (new ReservationMailRecipientResolver())->defaultRecipient([]));
    }

    public function testPrefilledRecipientSwitchesToTheInvoiceEmail(): void
    {
        $recipient = (new ReservationMailRecipientResolver())->recipientForAttachedInvoice(
            'Guest@example.com',
            $this->invoice('accounts@example.com'),
            $this->reservations('guest@example.com'),
        );

        self::assertSame('accounts@example.com', $recipient);
    }

    public function testEmptyRecipientIsFilledWithTheInvoiceEmail(): void
    {
        $recipient = (new ReservationMailRecipientResolver())->recipientForAttachedInvoice('', $this->invoice('accounts@example.com'), []);

        self::assertSame('accounts@example.com', $recipient);
    }

    public function testRecipientTypedInByTheUserIsKept(): void
    {
        $recipient = (new ReservationMailRecipientResolver())->recipientForAttachedInvoice(
            'someone@example.com',
            $this->invoice('accounts@example.com'),
            $this->reservations('guest@example.com'),
        );

        self::assertNull($recipient);
    }

    public function testNothingChangesWhenTheInvoiceHasNoOtherEmail(): void
    {
        $resolver = new ReservationMailRecipientResolver();
        $reservations = $this->reservations('guest@example.com');

        self::assertNull($resolver->recipientForAttachedInvoice('guest@example.com', $this->invoice(null), $reservations));
        self::assertNull($resolver->recipientForAttachedInvoice('guest@example.com', $this->invoice('guest@example.com'), $reservations));
    }

    /** @return list<Reservation> */
    private function reservations(?string ...$emails): array
    {
        $booker = new Customer();
        foreach ($emails as $email) {
            $booker->addCustomerAddress((new CustomerAddresses())->setEmail($email));
        }
        $reservation = new Reservation();
        $reservation->setBooker($booker);

        return [$reservation];
    }

    private function invoice(?string $email): Invoice
    {
        $invoice = new Invoice();
        $invoice->setEmail($email);

        return $invoice;
    }
}
