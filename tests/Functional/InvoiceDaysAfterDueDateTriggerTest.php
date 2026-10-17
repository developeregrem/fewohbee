<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Workflow\Trigger\InvoiceDaysAfterDueDateTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "X days after the due date" against a real database, matching the due date stored on
 * the invoice.
 */
final class InvoiceDaysAfterDueDateTriggerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InvoiceDaysAfterDueDateTrigger $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->trigger = static::getContainer()->get(InvoiceDaysAfterDueDateTrigger::class);
    }

    public function testMatchesAnInvoiceTheGivenDaysAfterItsDueDate(): void
    {
        $invoice = $this->invoice(dueInDays: -1);

        self::assertContains($invoice->getId(), $this->matching(days: 1));
        self::assertNotContains($invoice->getId(), $this->matching(days: 2));
    }

    /** The point of the trigger: an invoice given a later date is not reminded early. */
    public function testALaterDueDateHoldsTheInvoiceBack(): void
    {
        $invoice = $this->invoice(dueInDays: 5, invoiceDaysAgo: 11);

        self::assertNotContains($invoice->getId(), $this->matching(days: 1));
        self::assertNotContains($invoice->getId(), $this->matching(days: 0));
    }

    public function testZeroDaysMeansTheDueDateItself(): void
    {
        $invoice = $this->invoice(dueInDays: 0);

        self::assertContains($invoice->getId(), $this->matching(days: 0));
    }

    public function testNegativeDaysFireBeforeTheDueDate(): void
    {
        $invoice = $this->invoice(dueInDays: 3);

        self::assertContains($invoice->getId(), $this->matching(days: -3));
    }

    /** An issuer with free-text terms gives no due date, so nothing can be due. */
    public function testAnInvoiceWithoutDueDateNeverMatches(): void
    {
        $invoice = $this->invoice(dueInDays: 0);
        $this->em->getConnection()->executeStatement('UPDATE invoices SET payment_due_date = NULL WHERE id = ?', [$invoice->getId()]);

        self::assertNotContains($invoice->getId(), $this->matching(days: 0));
    }

    /** The limit is applied by the query, not after loading every candidate. */
    public function testTheLimitIsAppliedByTheQuery(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->invoice(dueInDays: -7);
        }

        $ids = $this->trigger->findMatchingIds($this->em, ['days' => 7, 'runOnDays' => 'daily', 'runAtHour' => 0], 2);

        self::assertCount(2, $ids);
    }

    public function testPreviewListsTheSameInvoices(): void
    {
        $invoice = $this->invoice(dueInDays: -1);

        $ids = array_map(static fn (Invoice $i): ?int => $i->getId(), $this->trigger->findPreviewEntities($this->em, ['days' => 1], 500));

        self::assertContains($invoice->getId(), $ids);
    }

    /** @return int[] */
    private function matching(int $days): array
    {
        return $this->trigger->findMatchingIds($this->em, ['days' => $days, 'runOnDays' => 'daily', 'runAtHour' => 0]);
    }

    private function invoice(int $dueInDays, int $invoiceDaysAgo = 1): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('DUETRG-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime(sprintf('today -%d days', $invoiceDaysAgo)));
        $invoice->setStatus(1);
        $invoice->setLastname('Due');
        $invoice->setPaymentDueDate(new \DateTimeImmutable(sprintf('today %+d days', $dueInDays)));
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }
}
