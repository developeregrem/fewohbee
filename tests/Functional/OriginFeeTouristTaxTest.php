<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\Enum\PaymentCollection;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Workflow\Action\CreatePercentageEntryAction;
use App\Workflow\Action\WorkflowActionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A stay with a tourist tax billed beside it, booked through a portal and run
 * the way a workflow runs it: through the registry, against the real journal.
 *
 * This is where the two bases visibly part company. Booking.com charges no
 * commission on a separately billed tourist tax, but does charge its payment
 * fee on it where it collected the money - so the same invoice yields two
 * different figures, and which one the payment fee uses hangs on one setting on
 * the reservation origin.
 *
 * The stay is 4 nights at 59.00 = 236.00, the tax 4 × 3.00 = 12.00, at 12 % and
 * 1.4 % - the rates the house has with Booking.com.
 */
final class OriginFeeTouristTaxTest extends WebTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testCommissionSkipsTheTouristTaxWhileThePaymentFeeTakesItIn(): void
    {
        // The portal collects the tax along with the stay, which is how it is
        // set up when the tax is entered on their side as a separate local tax.
        $invoice = $this->invoiceWithStayAndTouristTax(PaymentCollection::PORTAL);

        [$commission, $paymentFee] = $this->book($invoice);

        // 236.00 × 12 % = 28.32 - the tax stays out of it.
        self::assertSame('28.32', $commission->getAmount());
        // 248.00 × 1.4 % = 3.472 → 3.47, the tax counted in.
        self::assertSame('3.47', $paymentFee->getAmount());
    }

    public function testTheTaxDropsOutOfBothWhereTheHouseCollectsIt(): void
    {
        // Entered on the portal's side as payable on arrival: it brokered the
        // stay but never handled the tax, so it charges nothing on it.
        $invoice = $this->invoiceWithStayAndTouristTax(PaymentCollection::PROPERTY);

        [$commission, $paymentFee] = $this->book($invoice);

        self::assertSame('28.32', $commission->getAmount());
        // 236.00 × 1.4 % = 3.304 → 3.30, the stay alone.
        self::assertSame('3.30', $paymentFee->getAmount());
    }

    /**
     * Books the two deductions the way the two configured workflows do, and
     * hands back the entries they produced.
     *
     * @return array{0: BookingEntry, 1: BookingEntry}
     */
    private function book(Invoice $invoice): array
    {
        $action = static::getContainer()->get(WorkflowActionRegistry::class)->get('create_percentage_entry');
        $since = $this->lastEntryId();

        $action->execute($this->config(CreatePercentageEntryAction::PERCENT_SOURCE_COMMISSION), $invoice, []);
        $action->execute($this->config(CreatePercentageEntryAction::PERCENT_SOURCE_PAYMENT_FEE), $invoice, []);
        $this->em()->flush();

        $entries = $this->entriesSince($since);
        self::assertCount(2, $entries, 'both deductions have to be booked');

        return [$entries[0], $entries[1]];
    }

    /**
     * An invoice for one portal booking: four nights plus the tourist tax as a
     * position of its own, flagged as InvoiceService flags them.
     */
    private function invoiceWithStayAndTouristTax(PaymentCollection $taxCollectedBy): Invoice
    {
        $origin = new ReservationOrigin();
        $origin->setName('Booking.com Test');
        $origin->setCommissionPercent('12.00');
        $origin->setPaymentFeePercent('1.40');
        $origin->setPaymentCollection(PaymentCollection::PORTAL);
        $origin->setTouristTaxCollection($taxCollectedBy);
        $this->em()->persist($origin);

        $reservation = new Reservation();
        $reservation->setStartDate(new \DateTime('2026-08-14'));
        $reservation->setEndDate(new \DateTime('2026-08-18'));
        $reservation->setPersons(1);
        $reservation->setUuid(Uuid::v4());
        $reservation->setReservationStatus($this->anyReservationStatus());
        // Pins the origin's rates and who collects, as every booking path does.
        $reservation->setReservationOrigin($origin);
        $this->em()->persist($reservation);

        $invoice = new Invoice();
        $invoice->setNumber('T'.random_int(100000, 999999));
        $invoice->setDate(new \DateTime('2026-08-18'));
        $invoice->setStatus(1);
        $invoice->setRemark('');
        $this->em()->persist($invoice);

        $stay = new InvoiceAppartment();
        $stay->setInvoice($invoice);
        $stay->setNumber('6');
        $stay->setDescription('Einzelzimmer');
        $stay->setBeds(1);
        $stay->setPersons(1);
        $stay->setStartDate(new \DateTime('2026-08-14'));
        $stay->setEndDate(new \DateTime('2026-08-18'));
        $stay->setPrice(59.00);
        $stay->setVat(7.0);
        $stay->setIncludesVat(true);
        $stay->setIsFlatPrice(false);
        $this->em()->persist($stay);

        $tax = new InvoicePosition();
        $tax->setInvoice($invoice);
        $tax->setDescription('Kurtaxe');
        $tax->setAmount(4);
        $tax->setPrice(3.00);
        $tax->setVat(7.0);
        $tax->setIncludesVat(true);
        $tax->setIsFlatPrice(false);
        $tax->setIsPerRoom(false);
        $tax->setPositionGroup('tourist_tax');
        // What InvoiceService::makeTouristTaxPosition() records: never
        // commissionable, brokered only where the portal collects it.
        $tax->setCommissionable(false);
        $tax->setBrokered($taxCollectedBy->isPortal());
        $this->em()->persist($tax);

        $this->em()->flush();

        $invoice->getAppartments()->add($stay);
        $invoice->addPosition($tax);
        $invoice->addReservation($reservation);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private function config(string $percentSource): array
    {
        $taxRate = $this->em()->getRepository(TaxRate::class)->findOneBy([]);

        return [
            'percentSource' => $percentSource,
            'percent' => '',
            'debitAccountId' => (string) $this->account('3123')->getId(),
            'creditAccountId' => (string) $this->account('1200')->getId(),
            'taxRateId' => (string) $taxRate?->getId(),
            'remark' => '',
        ];
    }

    private function anyReservationStatus(): ReservationStatus
    {
        $status = $this->em()->getRepository(ReservationStatus::class)->findOneBy([]);
        self::assertNotNull($status, 'the fixture needs at least one reservation status');

        return $status;
    }

    private function account(string $number): AccountingAccount
    {
        /** @var AccountingAccountRepository $repo */
        $repo = $this->em()->getRepository(AccountingAccount::class);
        $account = $repo->findOneBy(['accountNumber' => $number]);

        if (null === $account) {
            $account = new AccountingAccount();
            $account->setAccountNumber($number);
            $account->setName('Testkonto '.$number);
            $account->setType('expense');
            $this->em()->persist($account);
            $this->em()->flush();
        }

        return $account;
    }

    /** @return BookingEntry[] */
    private function entriesSince(int $id): array
    {
        return $this->em()->getRepository(BookingEntry::class)
            ->createQueryBuilder('e')
            ->where('e.id > :id')
            ->setParameter('id', $id)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function lastEntryId(): int
    {
        return (int) ($this->em()->getRepository(BookingEntry::class)
            ->createQueryBuilder('e')
            ->select('MAX(e.id)')
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    private function em(): EntityManagerInterface
    {
        if (null === $this->em) {
            if (!static::$booted && null === static::$kernel) {
                self::bootKernel();
            }
            $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        }

        return $this->em;
    }
}
