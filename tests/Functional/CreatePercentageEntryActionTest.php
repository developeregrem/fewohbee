<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Workflow\Action\WorkflowActionRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs the action the way a workflow does - through the registry, against the
 * real journal service and a real invoice - so the arithmetic is checked
 * together with the wiring it depends on.
 *
 * The figures are taken from an invoice that was booked by hand: 115.20 gross
 * at 12% and 1.4% produced 13.82 and 1.61.
 */
final class CreatePercentageEntryActionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(ManagerRegistry::class)->getManager();
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

        $action->execute($this->config('12', 'Kommission %number%'), $invoice, []);
        $action->execute($this->config('1,4', 'Zahlungsgebühr %number%'), $invoice, []);
        $this->em->flush();

        $entries = $this->em->getRepository(BookingEntry::class)
            ->findBy(['invoiceNumber' => $invoice->getNumber()], ['id' => 'ASC']);

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

        $action->execute($this->config('12', ''), $invoice, []);
        $this->em->flush();

        $entry = $this->em->getRepository(BookingEntry::class)->findOneBy(['invoiceNumber' => $invoice->getNumber()]);

        self::assertSame($invoice->getDate()->format('Y-m-d'), $entry->getDate()->format('Y-m-d'));
        self::assertSame($this->account('3123')->getId(), $entry->getDebitAccount()?->getId());
        self::assertSame($this->account('1200')->getId(), $entry->getCreditAccount()?->getId());
        // Left unset on purpose: the bank import re-dates entries carrying one.
        self::assertNull($entry->getInvoiceId());
    }

    /** @return array<string, mixed> */
    private function config(string $percent, string $remark): array
    {
        $taxRate = $this->em->getRepository(TaxRate::class)->findOneBy([]);

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
        $repo = $this->em->getRepository(AccountingAccount::class);
        $account = $repo->findOneBy(['accountNumber' => $number]);

        if (null === $account) {
            $account = new AccountingAccount();
            $account->setAccountNumber($number);
            $account->setName('Testkonto '.$number);
            $account->setType('expense');
            $this->em->persist($account);
            $this->em->flush();
        }

        return $account;
    }

    private function createInvoice(float $gross): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('T'.random_int(100000, 999999));
        $invoice->setDate(new \DateTime('2026-06-21'));
        $invoice->setStatus(1);
        $invoice->setRemark('');
        $this->em->persist($invoice);

        $apartment = new InvoiceAppartment();
        $apartment->setInvoice($invoice);
        $apartment->setNumber('1');
        $apartment->setDescription('Testzimmer');
        $apartment->setBeds(2);
        $apartment->setPersons(2);
        $apartment->setStartDate(new \DateTime('2026-06-19'));
        $apartment->setEndDate(new \DateTime('2026-06-21'));
        $apartment->setPrice($gross);
        $apartment->setVat(7.0);
        $apartment->setIncludesVat(true);
        $apartment->setIsFlatPrice(true);
        $this->em->persist($apartment);
        $this->em->flush();

        $invoice->getAppartments()->add($apartment);

        return $invoice;
    }
}
