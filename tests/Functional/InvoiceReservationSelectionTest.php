<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use App\Entity\User;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceReservationSelectionTest extends WebTestCase
{
    private KernelBrowser $client;
    private ?string $invoiceNumber = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user, 'main');
    }

    #[DataProvider('draftScreens')]
    public function testAStaleSelectionIsRejectedWithoutChangingTheDraft(string $path): void
    {
        $this->prepareDraft();
        $this->makeSelectionStale();
        $before = $this->snapshot();
        $invoiceCount = $this->em()->getRepository(Invoice::class)->count([]);

        $this->client->request('GET', $path);

        $this->assertSelectionConflict();
        self::assertSame($before, $this->snapshot());
        self::assertSame($invoiceCount, $this->em()->getRepository(Invoice::class)->count([]));
    }

    /** @return iterable<string, array{string}> */
    public static function draftScreens(): iterable
    {
        yield 'positions' => ['/invoices/create/positions/create'];
        yield 'customer recommendations' => ['/invoices/create/invoice/customer/change'];
        yield 'preview' => ['/invoices/new/invoice/preview'];
        yield 'new room position' => ['/invoices/new/position/apartment/new'];
        yield 'edit room position' => ['/invoices/new/edit/position/apartment/0/edit'];
        yield 'new miscellaneous position' => ['/invoices/new/new/miscellaneous'];
    }

    #[DataProvider('staleSaveSelections')]
    public function testSavingAStaleSelectionCreatesNoInvoiceAndKeepsTheDraft(bool $keepExistingReservations): void
    {
        $preview = $this->prepareDraft();
        $form = $preview->filter('#create-new-invoice')->form();
        $this->makeSelectionStale($keepExistingReservations);
        $before = $this->snapshot();
        $invoiceCount = $this->em()->getRepository(Invoice::class)->count([]);

        $this->client->submit($form);

        $this->assertSelectionConflict();
        self::assertSame($before, $this->snapshot());
        self::assertSame($invoiceCount, $this->em()->getRepository(Invoice::class)->count([]));
    }

    /** @return iterable<string, array{bool}> */
    public static function staleSaveSelections(): iterable
    {
        yield 'some reservations are missing' => [true];
        yield 'no selected reservation remains' => [false];
    }

    public function testAValidSelectionStillSavesEveryReservationAndPosition(): void
    {
        $preview = $this->prepareDraft();
        $before = $this->snapshot();

        $this->client->submit($preview->filter('#create-new-invoice')->form());

        self::assertResponseIsSuccessful();
        $this->em()->clear();
        $invoice = $this->em()->getRepository(Invoice::class)->findOneBy(['number' => $this->invoiceNumber]);
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertEqualsCanonicalizing($before['selection'], $invoice->getReservations()->map(static fn (Reservation $reservation): int => $reservation->getId())->toArray());
        self::assertCount($before['apartments'], $invoice->getAppartments());
        self::assertCount($before['miscellaneous'], $invoice->getPositions());
    }

    public function testTheCreationDialogStillRequiresAuthentication(): void
    {
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/invoices/create/invoice/customer/change');

        self::assertResponseRedirects('/login');
    }

    private function prepareDraft(): Crawler
    {
        $reservations = $this->em()->getRepository(Reservation::class)->findBy([], ['id' => 'ASC'], 2);
        self::assertCount(2, $reservations);
        $this->client->request('GET', '/invoices/new?createNew=true');
        foreach ($reservations as $reservation) {
            $this->client->request('POST', '/invoices/select/reservation', ['reservationid' => $reservation->getId()]);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/invoices/create/positions/create');
        self::assertResponseIsSuccessful();
        $this->invoiceNumber = 'SELECTION-'.bin2hex(random_bytes(5));
        $this->client->request('POST', '/invoices/create/positions/meta', ['invoiceid' => $this->invoiceNumber]);
        self::assertResponseStatusCodeSame(204);
        $preview = $this->client->request('GET', '/invoices/new/invoice/preview');
        self::assertResponseIsSuccessful();

        return $preview;
    }

    private function makeSelectionStale(bool $keepExistingReservations = true): void
    {
        $last = $this->em()->getRepository(Reservation::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(Reservation::class, $last);
        $session = $this->session();
        $ids = $keepExistingReservations ? $session->get(ReservationService::SESSION_SELECTED_RESERVATIONS) : [];
        $ids[] = $last->getId() + 1;
        $session->set(ReservationService::SESSION_SELECTED_RESERVATIONS, $ids);
        $session->save();
    }

    /**
     * @return array{
     *     selection: array<int, int>, number: string, apartments: int, miscellaneous: int,
     *     apartmentPrices: array<int, string>, miscellaneousPrices: array<int, string>
     * }
     */
    private function snapshot(): array
    {
        $session = $this->session();
        $invoice = $session->get('newInvoice');
        self::assertInstanceOf(Invoice::class, $invoice);
        $snapshot = [
            'selection' => $session->get(ReservationService::SESSION_SELECTED_RESERVATIONS),
            'number' => $invoice->getNumber(),
            'apartments' => count($session->get('invoicePositionsAppartments')),
            'miscellaneous' => count($session->get('invoicePositionsMiscellaneous')),
            'apartmentPrices' => $session->get('invoicePositionsAppartments')->map(static fn (InvoiceAppartment $position): string => $position->getPrice())->toArray(),
            'miscellaneousPrices' => $session->get('invoicePositionsMiscellaneous')->map(static fn (InvoicePosition $position): string => $position->getPrice())->toArray(),
        ];
        $session->save();

        return $snapshot;
    }

    private function assertSelectionConflict(): void
    {
        self::assertResponseStatusCodeSame(409);
        $message = self::getContainer()->get(TranslatorInterface::class)->trans('invoice.reservations.unavailable');
        self::assertSelectorTextSame('.alert.alert-warning.alert-dismissible[role="alert"]', $message);
        self::assertSelectorExists('.alert-warning .btn-close[data-bs-dismiss="alert"]');
        self::assertSame('text/html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel && null !== $this->invoiceNumber) {
            $em = $this->em();
            $invoice = $em->getRepository(Invoice::class)->findOneBy(['number' => $this->invoiceNumber]);
            if ($invoice instanceof Invoice) {
                foreach ($invoice->getReservations() as $reservation) {
                    $reservation->removeInvoice($invoice);
                }
                foreach ($invoice->getAppartments() as $position) {
                    $em->remove($position);
                }
                foreach ($invoice->getPositions() as $position) {
                    $em->remove($position);
                }
                $em->remove($invoice);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    private function session(): SessionInterface
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $cookie = $this->client->getCookieJar()->get($session->getName());
        self::assertNotNull($cookie);
        $session->setId($cookie->getValue());
        $session->start();

        return $session;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
