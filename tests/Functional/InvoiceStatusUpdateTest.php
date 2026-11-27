<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The payment status saves itself once picked from its menu, in the invoice dialog and at the
 * status icon in the invoice list; both show a tick only for a successful answer, so every refusal has to come back
 * as an error.
 */
final class InvoiceStatusUpdateTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTheDialogSavesThePickedStatusWithoutAButton(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $crawler = $client->request('GET', '/invoices/get/'.$invoice->getId().'/');

        self::assertResponseIsSuccessful();
        $picker = $crawler->filter('#invoice-form-status [data-controller="invoice-status"]');
        self::assertSame('/invoices/update/'.$invoice->getId().'/status', $picker->attr('data-invoice-status-url-value'));
        self::assertSame('1', $picker->attr('data-invoice-status-status-value'));
        self::assertCount(4, $picker->filter('.dropdown-menu button[data-action="invoice-status#pick"]'));
        self::assertSame('true', $picker->filter('button[data-invoice-status-status-param="1"]')->attr('aria-current'));
        // In the dialog the toggle names the status next to its icon
        self::assertStringNotContainsString('visually-hidden', (string) $picker->filter('[data-invoice-status-target="toggle"] [data-invoice-status-label]')->attr('class'));
        self::assertCount(1, $picker->filter('.save-state[role="status"]'));
        self::assertCount(0, $crawler->filter('#save-status'));
    }

    public function testTheInvoiceListOffersTheStatusAtItsIcon(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $crawler = $client->request('GET', '/invoices/', ['search' => $invoice->getNumber()]);

        self::assertResponseIsSuccessful();
        $picker = $crawler->filter('.invoice-status-picker[data-invoice-status-id-value="'.$invoice->getId().'"]');
        self::assertCount(1, $picker);
        self::assertCount(4, $picker->filter('.dropdown-menu [data-action="invoice-status#pick"]'));
        // A touch screen shows no title: the menu names the payment means itself
        self::assertSame('Barzahlung', trim($picker->filter('.dropdown-menu .dropdown-header')->text()));
        // In the list only the payment means icon shows, in the colour of the status; the name is
        // there for screen readers
        // and a clock at its corner, so the status does not hang on the colour alone
        $icon = $picker->filter('[data-invoice-status-target="toggle"] [data-invoice-status-icon]');
        self::assertStringContainsString('text-warning', (string) $icon->attr('class'));
        self::assertCount(1, $icon->filter('.fa-coins'));
        self::assertCount(1, $icon->filter('.invoice-status-mark.fa-clock'));
        self::assertStringContainsString('visually-hidden', (string) $picker->filter('[data-invoice-status-target="toggle"] [data-invoice-status-label]')->attr('class'));

        $this->postStatus($client, $invoice, '2', (string) $picker->attr('data-invoice-status-token-value'));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->storedStatus($invoice));
    }

    public function testAPickedStatusIsStored(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $this->postStatus($client, $invoice, '2', $this->tokenFromDialog($client, $invoice));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->storedStatus($invoice));
    }

    public function testWithoutAValidTokenTheStatusStaysAndTheAnswerIsAnError(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();
        $this->tokenFromDialog($client, $invoice);

        $this->postStatus($client, $invoice, '2', 'not-the-token');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->storedStatus($invoice));
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser());
        $invoice = $this->createInvoice();

        $this->postStatus($client, $invoice, '9', $this->tokenFromDialog($client, $invoice));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(1, $this->storedStatus($invoice));
    }

    public function testTheOccupancyPlanCarriesTheStatusMenuForItsPopovers(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser(['ROLE_INVOICES', 'ROLE_RESERVATIONS_RO']));
        $invoice = $this->createInvoice();

        $crawler = $client->request('GET', '/reservation/');

        self::assertResponseIsSuccessful();
        $menu = $crawler->filter('template#invoice-status-popover-menu');
        self::assertCount(1, $menu);

        $this->postStatus($client, $invoice, '3', (string) $menu->attr('data-token'));

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->storedStatus($invoice));
    }

    public function testWithoutInvoiceRightsThePlanHasNoStatusMenu(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser(['ROLE_RESERVATIONS_RO']));

        $crawler = $client->request('GET', '/reservation/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('template#invoice-status-popover-menu'));
    }

    public function testTheFrontDeskOpensTheInvoiceAndChangesItsStatus(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser(['ROLE_INVOICES', 'ROLE_OPERATIONS']));
        $invoice = $this->createInvoice();
        $reservation = $this->createReservationWith($invoice);

        $crawler = $client->request('GET', '/operations/frontdesk', ['date' => $reservation->getStartDate()->format('Y-m-d'), 'subsidiary' => 'all']);

        self::assertResponseIsSuccessful();
        $selector = '[data-invoice-status-id-value="'.$invoice->getId().'"]';
        // Table (icon) and card (button "Rechnung") each have a single control: the status menu,
        // which opens the invoice as well
        $open = ' .dropdown-menu [data-url="/invoices/get/'.$invoice->getId().'/"]';
        self::assertCount(1, $crawler->filter('.frontdesk-table '.$selector.'.invoice-status-picker-compact'));
        self::assertCount(1, $crawler->filter('.frontdesk-card-actions > '.$selector.'.invoice-status-picker-button'));
        self::assertCount(1, $crawler->filter('.frontdesk-table '.$selector.$open));
        self::assertCount(1, $crawler->filter('.frontdesk-card-actions > '.$selector.$open));

        $this->postStatus($client, $invoice, '2', (string) $crawler->filter($selector)->first()->attr('data-invoice-status-token-value'));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->storedStatus($invoice));
    }

    public function testWithoutInvoiceRightsTheFrontDeskShowsThePlainStatus(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createInvoiceUser(['ROLE_OPERATIONS']));
        $invoice = $this->createInvoice();
        $reservation = $this->createReservationWith($invoice);

        $crawler = $client->request('GET', '/operations/frontdesk', ['date' => $reservation->getStartDate()->format('Y-m-d'), 'subsidiary' => 'all']);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller="invoice-status"]'));
        self::assertGreaterThan(0, $crawler->filter('.frontdesk-table .invoice-status-icon .fa-coins')->count());
    }

    private function createReservationWith(Invoice $invoice): Reservation
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $reservation = new Reservation();
        $reservation->setReservationOrigin($em->getRepository(ReservationOrigin::class)->findOneBy([]));
        $reservation->setReservationStatus($em->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => true]));
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime('+40 days'));
        $reservation->setEndDate(new \DateTime('+42 days'));
        $reservation->setAppartment($em->getRepository(Appartment::class)->findOneBy([]));
        $reservation->setReservationDate(new \DateTime());
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setUuid(Uuid::v4());
        $reservation->addInvoice($invoice);
        $em->persist($reservation);
        $em->flush();

        return $reservation;
    }

    private function tokenFromDialog(KernelBrowser $client, Invoice $invoice): string
    {
        $crawler = $client->request('GET', '/invoices/get/'.$invoice->getId().'/');

        return (string) $crawler->filter('#invoice-form-status [data-controller="invoice-status"]')->attr('data-invoice-status-token-value');
    }

    private function postStatus(KernelBrowser $client, Invoice $invoice, string $status, string $token): void
    {
        $client->request('POST', '/invoices/update/'.$invoice->getId().'/status', [
            'invoice-status' => $status,
            '_token' => $token,
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function storedStatus(Invoice $invoice): int
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->clear();

        return (int) $em->getRepository(Invoice::class)->find($invoice->getId())?->getStatus();
    }

    private function createInvoice(): Invoice
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        $invoice = new Invoice();
        $invoice->setNumber('STATUS-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime('2026-09-01'));
        $invoice->setStatus(1);
        $invoice->setLastname('Status');
        $invoice->setPaymentMeans(PaymentMeansCode::CASH);
        $em->persist($invoice);
        $em->flush();

        return $invoice;
    }

    /**
     * @param list<string> $roles
     */
    private function createInvoiceUser(array $roles = ['ROLE_INVOICES']): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $user = new User();
        $user->setUsername('status_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Invoices');
        $user->setEmail(sprintf('status+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setLastSeenVersion('99.99.99');

        $user->setRoleEntities(array_values(array_filter(array_map(
            static fn (string $name): ?Role => $em->getRepository(Role::class)->findOneBy(['role' => $name]),
            $roles,
        ))));

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
