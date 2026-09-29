<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceSettingsData;
use App\Workflow\Trigger\InvoiceDaysAfterDueDateTrigger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "X days after the due date" against a real database: the due date is the invoice's
 * own date where it has one, else its date plus the issuer's payment period.
 */
final class InvoiceDaysAfterDueDateTriggerTest extends KernelTestCase
{
    private const PERIOD = 10;

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
        $this->givenSettingsPeriod(self::PERIOD);
    }

    public function testMatchesAnInvoiceDueByTheSettingsPeriod(): void
    {
        $invoice = $this->invoice(invoiceDaysAgo: self::PERIOD + 1);

        self::assertContains($invoice->getId(), $this->matching(days: 1));
    }

    public function testMatchesAnInvoiceByItsOwnDueDate(): void
    {
        $invoice = $this->invoice(invoiceDaysAgo: 3, ownDueDaysAgo: 1);

        self::assertContains($invoice->getId(), $this->matching(days: 1));
    }

    /** The point of the trigger: an invoice given a later date is not reminded early. */
    public function testALaterOwnDueDateHoldsTheInvoiceBack(): void
    {
        $invoice = $this->invoice(invoiceDaysAgo: self::PERIOD + 1, ownDueDaysAgo: -5);

        self::assertNotContains($invoice->getId(), $this->matching(days: 1));
        self::assertNotContains($invoice->getId(), $this->matching(days: 0));
    }

    public function testZeroDaysMeansTheDueDateItself(): void
    {
        $invoice = $this->invoice(invoiceDaysAgo: self::PERIOD);

        self::assertContains($invoice->getId(), $this->matching(days: 0));
        self::assertNotContains($invoice->getId(), $this->matching(days: 1));
    }

    public function testNegativeDaysFireBeforeTheDueDate(): void
    {
        $invoice = $this->invoice(invoiceDaysAgo: 1, ownDueDaysAgo: -3);

        self::assertContains($invoice->getId(), $this->matching(days: -3));
    }

    public function testPreviewListsTheSameInvoices(): void
    {
        $invoice = $this->invoice(invoiceDaysAgo: self::PERIOD + 1);

        $ids = array_map(static fn (Invoice $i): ?int => $i->getId(), $this->trigger->findPreviewEntities($this->em, ['days' => 1], 500));

        self::assertContains($invoice->getId(), $ids);
    }

    /** @return int[] */
    private function matching(int $days): array
    {
        return $this->trigger->findMatchingIds($this->em, ['days' => $days, 'runOnDays' => 'daily', 'runAtHour' => 0]);
    }

    private function invoice(int $invoiceDaysAgo, ?int $ownDueDaysAgo = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('DUETRG-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime(sprintf('today -%d days', $invoiceDaysAgo)));
        $invoice->setStatus(1);
        $invoice->setLastname('Due');
        if (null !== $ownDueDaysAgo) {
            $invoice->setPaymentDueDate(new \DateTimeImmutable(sprintf('today %+d days', -$ownDueDaysAgo)));
        }
        $this->em->persist($invoice);
        $this->em->flush();

        return $invoice;
    }

    /** Whichever settings row an invoice resolves to, it states this period. */
    private function givenSettingsPeriod(int $days): void
    {
        $all = $this->em->getRepository(InvoiceSettingsData::class)->findAll();
        foreach ($all as $settings) {
            $settings->setPaymentDueDays($days);
        }
        if ([] === array_filter($all, static fn (InvoiceSettingsData $s): bool => (bool) $s->isActive())) {
            $settings = new InvoiceSettingsData();
            $settings->setCompanyName('Hotel Test');
            $settings->setCompanyAddress('Musterweg 1');
            $settings->setCompanyPostCode('12345');
            $settings->setCompanyCity('Musterstadt');
            $settings->setCompanyCountry('DE');
            $settings->setContactName('Max Mustermann');
            $settings->setContactPhone('+49 30 123456');
            $settings->setContactMail('kontakt@example.com');
            $settings->setCompanyInvoiceMail('rechnung@example.com');
            $settings->setAccountIBAN('DE44120300001089790461');
            $settings->setAccountName('Hotel Test');
            $settings->setAccountBIC('BYLADEM1001');
            $settings->setPaymentDueDays($days);
            $settings->setEinvoiceProfile('en16931');
            $settings->setIsActive(true);
            $this->em->persist($settings);
        }
        $this->em->flush();
    }
}
