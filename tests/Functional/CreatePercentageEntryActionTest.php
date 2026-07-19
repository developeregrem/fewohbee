<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\BookingEntryRepository;
use App\Workflow\Action\WorkflowActionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Runs the action the way a workflow does - through the registry, against the
 * real journal service and a real invoice - so the arithmetic is checked
 * together with the wiring it depends on.
 *
 * The figures are taken from an invoice that was booked by hand: 115.20 gross
 * at 12% and 1.4% produced 13.82 and 1.61.
 */
final class CreatePercentageEntryActionTest extends WebTestCase
{
    private ?EntityManagerInterface $em = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    /** Booted on first use so a test may create a client of its own instead. */
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

    public function testTheActionIsRegisteredUnderItsType(): void
    {
        $registry = static::getContainer()->get(WorkflowActionRegistry::class);

        self::assertTrue($registry->has('create_percentage_entry'));
        self::assertSame([Invoice::class], $registry->get('create_percentage_entry')->getSupportedEntityClasses());
    }

    public function testBooksCommissionAndFeeTheWayTheyWereBookedByHand(): void
    {
        $invoice = $this->createInvoice(115.20);
        $action = static::getContainer()->get(WorkflowActionRegistry::class)->get('create_percentage_entry');
        $since = $this->lastEntryId();

        $action->execute($this->config('12', 'Kommission %number%'), $invoice, []);
        $action->execute($this->config('1,4', 'Zahlungsgebühr %number%'), $invoice, []);
        $this->em()->flush();

        $entries = $this->entriesSince($since);

        self::assertCount(2, $entries);
        self::assertSame('13.82', $entries[0]->getAmount());
        self::assertSame('Kommission '.$invoice->getNumber(), $entries[0]->getRemark());
        self::assertSame('1.61', $entries[1]->getAmount());
        self::assertSame('Zahlungsgebühr '.$invoice->getNumber(), $entries[1]->getRemark());
    }

    public function testTheEntryCarriesTheInvoiceDateAndTheConfiguredAccounts(): void
    {
        $invoice = $this->createInvoice(115.20);
        $action = static::getContainer()->get(WorkflowActionRegistry::class)->get('create_percentage_entry');
        $since = $this->lastEntryId();

        $action->execute($this->config('12', ''), $invoice, []);
        $this->em()->flush();

        $entry = $this->entriesSince($since)[0];

        self::assertSame($invoice->getDate()->format('Y-m-d'), $entry->getDate()->format('Y-m-d'));
        self::assertSame($this->account('3123')->getId(), $entry->getDebitAccount()?->getId());
        self::assertSame($this->account('1200')->getId(), $entry->getCreditAccount()?->getId());
        // Left unset on purpose: the bank import re-dates entries carrying one.
        self::assertNull($entry->getInvoiceId());
    }

    public function testTheEntryWaitsForItsDocumentNumber(): void
    {
        // The reference that belongs here is the supplier's invoice for the
        // deduction, which does not exist yet - so the entry says it is
        // incomplete instead of carrying a stand-in.
        $invoice = $this->createInvoice(115.20);
        $action = static::getContainer()->get(WorkflowActionRegistry::class)->get('create_percentage_entry');
        $since = $this->lastEntryId();

        $action->execute($this->config('12', ''), $invoice, []);
        $this->em()->flush();

        $entry = $this->entriesSince($since)[0];

        self::assertNull($entry->getInvoiceNumber());
        self::assertTrue($entry->requiresDocumentNumber());
        self::assertTrue($entry->isMissingDocumentNumber());

        $entry->setInvoiceNumber('1656376969');
        self::assertFalse($entry->isMissingDocumentNumber(), 'supplying the number completes the entry');
    }

    public function testTheBatchCountsTheWaitingEntry(): void
    {
        $invoice = $this->createInvoice(115.20);
        $action = static::getContainer()->get(WorkflowActionRegistry::class)->get('create_percentage_entry');
        $since = $this->lastEntryId();

        $action->execute($this->config('12', ''), $invoice, []);
        $this->em()->flush();

        $entry = $this->entriesSince($since)[0];
        $repo = static::getContainer()->get(BookingEntryRepository::class);
        $before = $repo->countMissingDocumentNumber($entry->getBookingBatch());

        $entry->setInvoiceNumber('1656376969');
        $this->em()->flush();

        self::assertSame($before - 1, $repo->countMissingDocumentNumber($entry->getBookingBatch()));
    }

    public function testTheMonthCannotBeClosedWhileAnEntryWaits(): void
    {
        // The guard is the point of the flag: without it the month could be
        // finalised with a deduction that has no document behind it.
        $client = static::createClient();
        $client->loginUser($this->adminUser());

        // A month of its own: entries left waiting by the other tests would
        // otherwise keep this batch open no matter what happens here.
        $invoice = $this->createInvoice(115.20, '2026-09-15');
        $action = static::getContainer()->get(WorkflowActionRegistry::class)->get('create_percentage_entry');
        $since = $this->lastEntryId();
        $action->execute($this->config('12', ''), $invoice, []);
        $this->em()->flush();

        $entry = $this->entriesSince($since)[0];
        $entryId = $entry->getId();
        $batchId = $entry->getBookingBatch()->getId();

        // Each client request reboots the kernel, so state is read back from
        // the database rather than from entities of an earlier container.
        $this->toggleBatch($client, $batchId);
        self::assertFalse($this->isBatchClosed($batchId), 'batch closed despite a waiting entry');

        $this->setEntryDocumentNumber($entryId, '1656376969');

        $this->toggleBatch($client, $batchId);
        self::assertTrue($this->isBatchClosed($batchId), 'batch stayed open although the number was supplied');

        // Leave the fixture as found - a closed batch would block later tests.
        $this->reopenBatch($batchId);
    }

    private function isBatchClosed(int $id): bool
    {
        return (bool) $this->connection()->fetchOne('SELECT is_closed FROM booking_batches WHERE id = ?', [$id]);
    }

    private function reopenBatch(int $id): void
    {
        $this->connection()->executeStatement('UPDATE booking_batches SET is_closed = 0 WHERE id = ?', [$id]);
    }

    private function setEntryDocumentNumber(int $id, string $number): void
    {
        $this->connection()->executeStatement('UPDATE booking_entries SET invoice_number = ? WHERE id = ?', [$number, $id]);
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(ManagerRegistry::class)->getConnection();
    }

    /**
     * Entries created after the given id - the action reports a log line, not
     * the entry, and matching on the remark would catch unrelated rows.
     *
     * @return BookingEntry[]
     */
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

    /**
     * Submits the close/reopen form the way the page offers it - the token has
     * to come from that page, since minting one needs a session the test
     * process does not have.
     */
    private function toggleBatch(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $batchId): void
    {
        $crawler = $client->request('GET', '/journal/batch/'.$batchId);
        $form = $crawler->filter('form[action$="/toggle-status"]')->first();
        self::assertGreaterThan(0, $form->count(), 'no close/reopen form on the batch page');

        $client->submit($form->form());
    }

    private function adminUser(): \App\Entity\User
    {
        $user = $this->em()->getRepository(\App\Entity\User::class)->findOneBy([]);
        self::assertNotNull($user, 'the fixture needs at least one user');

        return $user;
    }

    private function lastEntryId(): int
    {
        return (int) ($this->em()->getRepository(BookingEntry::class)
            ->createQueryBuilder('e')
            ->select('MAX(e.id)')
            ->getQuery()
            ->getSingleScalarResult() ?? 0);
    }

    /** @return array<string, mixed> */
    private function config(string $percent, string $remark): array
    {
        $taxRate = $this->em()->getRepository(TaxRate::class)->findOneBy([]);

        return [
            'percent' => $percent,
            'debitAccountId' => (string) $this->account('3123')->getId(),
            'creditAccountId' => (string) $this->account('1200')->getId(),
            'taxRateId' => (string) $taxRate?->getId(),
            'remark' => $remark,
        ];
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

    private function createInvoice(float $gross, string $date = '2026-06-21'): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('T'.random_int(100000, 999999));
        $invoice->setDate(new \DateTime($date));
        $invoice->setStatus(1);
        $invoice->setRemark('');
        $this->em()->persist($invoice);

        $apartment = new InvoiceAppartment();
        $apartment->setInvoice($invoice);
        $apartment->setNumber('1');
        $apartment->setDescription('Testzimmer');
        $apartment->setBeds(2);
        $apartment->setPersons(2);
        $apartment->setStartDate((new \DateTime($date))->modify('-2 days'));
        $apartment->setEndDate(new \DateTime($date));
        $apartment->setPrice($gross);
        $apartment->setVat(7.0);
        $apartment->setIncludesVat(true);
        $apartment->setIsFlatPrice(true);
        $this->em()->persist($apartment);
        $this->em()->flush();

        $invoice->getAppartments()->add($apartment);

        return $invoice;
    }
}
