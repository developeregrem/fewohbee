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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The payment due date of a single invoice is set in the modal for payment method and remark.
 */
final class InvoicePaymentDueFormTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testDaysAreStoredAsADateAndShown(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $this->submit($client, $invoice, 'days', ['paymentDueDays' => '21']);

        self::assertResponseIsSuccessful();
        // The saved form forwards to the invoice view, which states the resulting date.
        self::assertStringContainsString('22.09.2026', (string) $client->getResponse()->getContent());
        self::assertSame('2026-09-22', $this->reload($invoice)->getPaymentDueDate()?->format('Y-m-d'));
    }

    public function testDaysModeWithoutDaysIsRejected(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $crawler = $this->submit($client, $invoice, 'days', ['paymentDueDays' => '']);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('#invoice_payment_remark_paymentDueDays.is-invalid')->count());
        $message = $crawler->filter('[data-payment-due-field="days"] .invalid-feedback')->text();
        self::assertStringContainsString('365', $message);
        self::assertStringNotContainsString('invoice.payment_due', $message);
        self::assertNull($this->reload($invoice)->getPaymentDueDate());
    }

    public function testLastDepartureIsStoredAsADate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $this->submit($client, $invoice, 'last_departure');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-09-05', $this->reload($invoice)->getPaymentDueDate()?->format('Y-m-d'));
    }

    /** An invoice written after the stay is due at once, not on a day already past. */
    public function testALastDepartureBeforeTheInvoiceDateMeansTheInvoiceDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice('2026-09-10');

        $this->submit($client, $invoice, 'last_departure');

        self::assertSame('2026-09-10', $this->reload($invoice)->getPaymentDueDate()?->format('Y-m-d'));
    }

    public function testAnOwnDateReopensAsFixedDateAndSettingsClearIt(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice(ownDueDate: '2026-10-15');

        $crawler = $client->request('GET', sprintf('/invoices/%d/edit/remark', $invoice->getId()));
        self::assertNotNull($crawler->filter('input[name="invoice_payment_remark[paymentDueMode]"][value="date"]')->attr('checked'));
        self::assertSame('2026-10-15', $crawler->filter('#invoice_payment_remark_paymentDueDate')->attr('value'));

        $this->submit($client, $invoice, 'settings');

        self::assertNull($this->reload($invoice)->getPaymentDueDate());
    }

    public function testLastDepartureIsUnavailableWithoutRoomPositions(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice(withApartment: false);

        $crawler = $client->request('GET', sprintf('/invoices/%d/edit/remark', $invoice->getId()));

        self::assertNotNull($crawler->filter('input[name="invoice_payment_remark[paymentDueMode]"][value="last_departure"]')->attr('disabled'));
    }

    /** The creation preview offers the same choice and names the settings period too. */
    public function testNewInvoicePreviewNamesTheSettingsPeriod(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $this->givenSettingsPeriod(10);

        $crawler = $this->openNewInvoicePreview($client);

        $settingsLabel = $crawler->filter('label[for="invoice_payment_remark_paymentDueMode_0"]');
        self::assertStringContainsString('10', $settingsLabel->text());
    }

    public function testNewInvoiceIsCreatedWithTheResultingDueDate(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $this->givenSettingsPeriod(10);
        $crawler = $this->openNewInvoicePreview($client);

        $form = $crawler->filter('form#create-new-invoice')->form();
        $modeField = $form['invoice_payment_remark[paymentDueMode]'];
        self::assertInstanceOf(ChoiceFormField::class, $modeField);
        $modeField->select('days');
        $form['invoice_payment_remark[paymentDueDays]'] = '5';
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $created = $em->getRepository(Invoice::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Invoice::class, $created);
        $expected = \DateTimeImmutable::createFromInterface($created->getDate())->modify('+5 days');
        self::assertSame($expected->format('Y-m-d'), $created->getPaymentDueDate()?->format('Y-m-d'));
    }

    private function openNewInvoicePreview(KernelBrowser $client): Crawler
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $reservation = $em->getRepository(Reservation::class)->findOneBy([]);
        self::assertInstanceOf(Reservation::class, $reservation, 'The seeded test data contains reservations.');

        $client->request('GET', '/invoices/new?createNew=true');
        $client->request('POST', '/invoices/select/reservation', ['reservationid' => $reservation->getId()]);
        $client->request('GET', '/invoices/create/positions/create');
        self::assertResponseIsSuccessful();
        $crawler = $client->request('GET', '/invoices/new/invoice/preview');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form#create-new-invoice'));

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

    /** @param array<string, string> $fields */
    private function submit(KernelBrowser $client, Invoice $invoice, string $mode, array $fields = []): Crawler
    {
        $crawler = $client->request('GET', sprintf('/invoices/%d/edit/remark', $invoice->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="invoice_payment_remark"]')->form();
        $modeField = $form['invoice_payment_remark[paymentDueMode]'];
        self::assertInstanceOf(ChoiceFormField::class, $modeField);
        $modeField->select($mode);
        foreach ($fields as $name => $value) {
            $form['invoice_payment_remark['.$name.']'] = $value;
        }

        return $client->submit($form);
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
