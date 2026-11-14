<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\InvoicePosition;
use App\Entity\Reservation;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class InvoicePositionVatFormTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    #[DataProvider('vatInputs')]
    public function testVatInputIsValidatedBeforeChangingAPosition(bool $apartment, bool $draft, bool $edit, string $vat): void
    {
        $reservation = $this->em()->getRepository(Reservation::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(Reservation::class, $reservation);
        $room = $reservation->getAppartment();
        self::assertNotNull($room);
        $number = $room->getNumber();
        $position = $this->position($apartment, $number);
        $class = $position::class;
        $sessionKey = $apartment ? 'invoicePositionsAppartments' : 'invoicePositionsMiscellaneous';

        if ($draft) {
            $this->client->request('GET', '/invoices/new?createNew=true');
            $this->client->request('POST', '/invoices/select/reservation', ['reservationid' => $reservation->getId()]);
            self::assertResponseIsSuccessful();
            $this->client->request('GET', '/invoices/create/positions/create');
            self::assertResponseIsSuccessful();
            $session = $this->session();
            $session->set($sessionKey, new ArrayCollection($edit ? [$position] : []));
            $session->save();
            $invoiceId = 'new';
            $positionId = 0;
        } else {
            $invoice = new Invoice();
            $invoice->setNumber('VAT-'.bin2hex(random_bytes(5)));
            $invoice->setDate(new \DateTime('2031-06-01'));
            $invoice->setLastname('VAT regression');
            $reservation->addInvoice($invoice);
            $invoice->addReservation($reservation);
            $this->em()->persist($invoice);
            if ($edit) {
                $position->setInvoice($invoice);
                $this->em()->persist($position);
            }
            $this->em()->flush();
            $invoiceId = $invoice->getId();
            $positionId = $position->getId();
        }

        $path = $apartment
            ? ($edit ? '/invoices/%s/edit/position/apartment/%s/edit' : '/invoices/%s/position/apartment/new')
            : ($edit ? '/invoices/%s/edit/miscellaneous/%s/edit' : '/invoices/%s/new/miscellaneous');
        $formName = $apartment ? 'invoice_apartment_position' : 'invoice_misc_position';
        $crawler = $this->client->request('GET', sprintf($path, $invoiceId, $positionId));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="'.$formName.'"]')->form();
        $form[$formName.'[description]'] = 'Submitted VAT regression';
        $form[$formName.'[price]'] = '24';
        $form[$formName.'[vat]'] = $vat;
        if ($apartment) {
            $form[$formName.'[number]'] = $number;
            $form[$formName.'[persons]'] = '1';
            $form[$formName.'[beds]'] = '1';
            $form[$formName.'[startDate]'] = '2031-06-01';
            $form[$formName.'[endDate]'] = '2031-06-03';
        } else {
            $form[$formName.'[amount]'] = '1';
        }

        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $valid = '' !== $vat;
        if (!$valid) {
            self::assertCount(1, $crawler->filter('#'.$formName.'_vat.is-invalid'));
            self::assertGreaterThan(0, $crawler->filter('#'.$formName.'_vat')->ancestors()->first()->filter('.invalid-feedback')->count());
        }
        if ($draft) {
            $session = $this->session();
            $positions = $session->get($sessionKey);
            self::assertCount($edit || $valid ? 1 : 0, $positions);
            $stored = $positions->first() ?: null;
            $session->save();
        } else {
            $this->em()->clear();
            $positions = $this->em()->getRepository($class)->findBy(['invoice' => $invoiceId]);
            self::assertCount($edit || $valid ? 1 : 0, $positions);
            $stored = $positions[0] ?? null;
        }
        if ($edit || $valid) {
            self::assertInstanceOf($class, $stored);
            self::assertSame($valid ? 0.0 : 19.0, $stored->getVat());
            self::assertSame($valid ? '24.00' : '20.00', $stored->getPrice());
            self::assertSame($valid ? 'Submitted VAT regression' : 'Original VAT regression', $stored->getDescription());
        }
    }

    /** @return iterable<string, array{bool, bool, bool, string}> */
    public static function vatInputs(): iterable
    {
        foreach ([true, false] as $apartment) {
            foreach ([true, false] as $draft) {
                foreach ([true, false] as $edit) {
                    foreach (['', '0'] as $vat) {
                        $name = ($apartment ? 'room' : 'misc').' '.($draft ? 'draft' : 'saved invoice')
                            .' '.($edit ? 'edit' : 'create').' '.('' === $vat ? 'missing VAT' : 'zero VAT');
                        yield $name => [$apartment, $draft, $edit, $vat];
                    }
                }
            }
        }
    }

    private function position(bool $apartment, string $number): InvoiceAppartment|InvoicePosition
    {
        if ($apartment) {
            $position = new InvoiceAppartment();
            $position->setNumber($number);
            $position->setPersons(1);
            $position->setBeds(1);
            $position->setStartDate(new \DateTime('2031-06-01'));
            $position->setEndDate(new \DateTime('2031-06-03'));
        } else {
            $position = new InvoicePosition();
            $position->setAmount(1);
        }
        $position->setDescription('Original VAT regression');
        $position->setPrice('20.00');
        $position->setVat(19);

        return $position;
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
