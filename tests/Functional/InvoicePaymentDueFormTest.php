<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoiceSettingsData;
use App\Entity\Reservation;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The payment due date of an invoice: prefilled from the settings when the invoice is
 * created, stored from then on, and chosen - at creation and later - next to the invoice
 * number and date.
 */
final class InvoicePaymentDueFormTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testNumberDialogShowsTheStoredDateAndItsMenu(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $this->givenSettingsPeriod(10);
        $invoice = $this->createInvoice(ownDueDate: '2026-09-11');

        $crawler = $client->request('GET', $this->editUrl($invoice));

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-11', $crawler->filter('#payment_due_date')->attr('value'));
        // Only the settings' period as a fixed choice, plus an own period typed in.
        self::assertSame(['10'], $crawler->filter('[data-days]')->each(static fn (Crawler $item): string => (string) $item->attr('data-days')));
        self::assertStringContainsString('Standard', $crawler->filter('[data-days="10"]')->text());
        $ownPeriod = $crawler->filter('.dropdown-menu input[type="number"]');
        self::assertCount(1, $ownPeriod);
        self::assertNull($ownPeriod->attr('name'), 'The own period only fills in the date.');
        // First arrival and last departure of the room positions, both from the invoice date on.
        $stayDates = $crawler->filter('[data-payment-due-stay]');
        self::assertSame(['2026-09-02', '2026-09-05'], $stayDates->each(static fn (Crawler $item): string => (string) $item->attr('data-payment-due-stay')));
        $stayDates->each(static fn (Crawler $item) => self::assertStringNotContainsString('d-none', (string) $item->attr('class')));
    }

    /** The menu only fills in the date in the browser; nothing of it reaches the server. */
    public function testOnlyTheDateIsSubmitted(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice(ownDueDate: '2026-09-11');

        $crawler = $client->request('GET', $this->editUrl($invoice));
        $fields = array_keys($crawler->filter('#invoice-number-form')->form()->getValues());

        sort($fields);
        self::assertSame(['_csrf_token', 'date', 'invoice-id', 'number', 'payment_due_date'], $fields);
    }

    public function testAChangedDateIsStoredAndShown(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice(ownDueDate: '2026-09-11');

        $crawler = $this->submit($client, $invoice, '2026-09-22');

        self::assertResponseIsSuccessful();
        // The saved dialog forwards to the invoice view, which states the date by the invoice date.
        $header = implode(' ', $crawler->filter('.col.text-end')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertStringContainsString('22.09.2026', $header);
        self::assertSame('2026-09-22', $this->reload($invoice)->getPaymentDueDate()?->format('Y-m-d'));
    }

    public function testAnEmptyDateIsRejectedWhileTheSettingsStateAPeriod(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $this->givenSettingsPeriod(10);
        $invoice = $this->createInvoice(ownDueDate: '2026-09-11');

        $crawler = $this->submit($client, $invoice, '');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#invoice-number-form'), 'The dialog is shown again.');
        self::assertSame('2026-09-11', $this->reload($invoice)->getPaymentDueDate()?->format('Y-m-d'));
    }

    /** A due date before the invoice date makes no sense, so arrival and departure are not offered then. */
    public function testStayDatesBeforeTheInvoiceDateAreNotOffered(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice('2026-09-10', ownDueDate: '2026-09-20');

        $crawler = $client->request('GET', $this->editUrl($invoice));

        $crawler->filter('[data-payment-due-stay], [data-payment-due-stay-divider]')
            ->each(static fn (Crawler $item) => self::assertStringContainsString('d-none', (string) $item->attr('class')));
    }

    /** Arrival on or after the invoice date stays offered while the departure is too. */
    public function testOnlyTheArrivalBeforeTheInvoiceDateIsHidden(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice('2026-09-03', ownDueDate: '2026-09-13');

        $crawler = $client->request('GET', $this->editUrl($invoice));

        self::assertStringContainsString('d-none', (string) $crawler->filter('[data-payment-due-stay="2026-09-02"]')->attr('class'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-payment-due-stay="2026-09-05"]')->attr('class'));
        self::assertStringNotContainsString('d-none', (string) $crawler->filter('[data-payment-due-stay-divider]')->attr('class'));
    }

    public function testNoStayDatesWithoutRoomPositions(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice(ownDueDate: '2026-09-11', withApartment: false);

        $crawler = $client->request('GET', $this->editUrl($invoice));

        self::assertCount(0, $crawler->filter('[data-payment-due-stay]'));
    }

    /** Once the invoice exists, payment method and remark are edited without the due date. */
    public function testTheRemarkDialogNoLongerCarriesTheDueDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice(ownDueDate: '2026-09-11');

        $crawler = $client->request('GET', sprintf('/invoices/%d/edit/remark', $invoice->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#invoice_payment_remark_paymentDueDate'));
    }

    /** Every new invoice is given the settings' date, whichever way it is created. */
    public function testAnInvoiceSavedWithoutDateGetsTheSettingsDate(): void
    {
        static::createClient();
        $this->givenSettingsPeriod(10);

        $invoice = $this->createInvoice();

        self::assertSame('2026-09-11', $this->reload($invoice)->getPaymentDueDate()?->format('Y-m-d'));
    }

    /** The due date is chosen in the step with number and date, starting with the settings' one. */
    public function testCreationStepIsPrefilledWithTheSettingsDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $this->givenSettingsPeriod(10);

        $crawler = $this->openCreationStep($client);

        $invoiceDate = (string) $crawler->filter('#invoiceDate')->attr('value');
        $expected = (new \DateTimeImmutable($invoiceDate))->modify('+10 days')->format('Y-m-d');
        self::assertSame($expected, $crawler->filter('#paymentDueDate')->attr('value'));
        self::assertCount(1, $crawler->filter('#invoice-meta [data-days]'));
    }

    /** Saved on the fly like number and date, shown in the preview and kept on the invoice. */
    public function testNewInvoiceIsCreatedWithTheChosenDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $this->givenSettingsPeriod(10);
        $crawler = $this->openCreationStep($client);

        $client->request('POST', '/invoices/create/positions/meta', [
            'invoiceid' => (string) $crawler->filter('#invoiceidInput')->attr('value'),
            'invoiceDate' => (string) $crawler->filter('#invoiceDate')->attr('value'),
            'paymentDueDate' => '2027-01-15',
        ]);
        self::assertResponseStatusCodeSame(204);
        $preview = $client->request('GET', '/invoices/new/invoice/preview');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('15.01.2027', $preview->filter('.modal-body')->text());
        self::assertCount(0, $preview->filter('#invoice_payment_remark_paymentDueDate'), 'The preview only shows the date.');
        $client->submit($preview->filter('form#create-new-invoice')->form());

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $created = $em->getRepository(Invoice::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Invoice::class, $created);
        self::assertSame('2027-01-15', $created->getPaymentDueDate()?->format('Y-m-d'));
    }

    private function openCreationStep(KernelBrowser $client): Crawler
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $reservation = $em->getRepository(Reservation::class)->findOneBy([]);
        self::assertInstanceOf(Reservation::class, $reservation, 'The seeded test data contains reservations.');

        $client->request('GET', '/invoices/new?createNew=true');
        $client->request('POST', '/invoices/select/reservation', ['reservationid' => $reservation->getId()]);
        $crawler = $client->request('GET', '/invoices/create/positions/create');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#invoice-meta #paymentDueDate'));

        return $crawler;
    }

    /** Whichever settings row the invoice resolves to, it states this period. */
    private function givenSettingsPeriod(int $days): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $all = $em->getRepository(InvoiceSettingsData::class)->findAll();
        foreach ($all as $settings) {
            $settings->setPaymentDueDays($days);
        }
        if ([] === array_filter($all, static fn (InvoiceSettingsData $s): bool => $s->isActive())) {
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
            $em->persist($settings);
        }
        $em->flush();
    }

    private function submit(KernelBrowser $client, Invoice $invoice, string $dueDate): Crawler
    {
        $crawler = $client->request('GET', $this->editUrl($invoice));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#invoice-number-form')->form();
        $form['payment_due_date'] = $dueDate;

        return $client->submit($form);
    }

    private function editUrl(Invoice $invoice): string
    {
        return sprintf('/invoices/%d/edit/number/show', $invoice->getId());
    }

    private function reload(Invoice $invoice): Invoice
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();

        return $em->getRepository(Invoice::class)->find($invoice->getId());
    }

    private function createInvoice(string $date = '2026-09-01', ?string $ownDueDate = null, bool $withApartment = true): Invoice
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $invoice = new Invoice();
        $invoice->setNumber('DUE-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime($date));
        $invoice->setStatus(1);
        $invoice->setLastname('Due');
        $invoice->setPaymentDueDate(null === $ownDueDate ? null : new \DateTimeImmutable($ownDueDate));
        $em->persist($invoice);

        if (!$withApartment) {
            $em->flush();

            return $invoice;
        }

        $apartment = new InvoiceAppartment();
        $apartment->setNumber('1');
        $apartment->setDescription('Zimmer 1');
        $apartment->setBeds(2);
        $apartment->setPersons(2);
        $apartment->setStartDate(new \DateTime('2026-09-02'));
        $apartment->setEndDate(new \DateTime('2026-09-05'));
        $apartment->setPrice('80.00');
        $apartment->setVat(7);
        $apartment->setIncludesVat(true);
        $apartment->setIsFlatPrice(false);
        $apartment->setIsPerRoom(true);
        $apartment->setInvoice($invoice);
        $invoice->addAppartment($apartment);

        $em->persist($apartment);
        $em->flush();

        return $invoice;
    }

    private function createInvoiceUser(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $user = new User();
        $user->setUsername('due_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Invoices');
        $user->setEmail(sprintf('due+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setLastSeenVersion('99.99.99');

        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_INVOICES']);
        $user->setRoleEntities(null !== $role ? [$role] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
