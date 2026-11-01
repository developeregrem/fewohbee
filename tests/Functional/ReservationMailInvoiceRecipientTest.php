<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\Role;
use App\Entity\Template;
use App\Entity\User;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Writing a mail from the reservation correspondence: attaching an invoice points the
 * recipient at the invoice's email, unless the user typed in a recipient of their own.
 */
final class ReservationMailInvoiceRecipientTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testAttachingAnInvoiceSwitchesThePrefilledRecipient(): void
    {
        $client = $this->loggedInClient();
        [$reservation, $template, $invoice] = $this->arrange();

        $crawler = $this->writeMailAndAttach($client, $reservation, $template, $invoice, 'guest@example.com');

        self::assertSame('accounts@example.com', $crawler->filter('#template-to')->attr('value'));
        self::assertStringContainsString((string) $invoice->getNumber(), $crawler->filter('#template-to + .form-text')->text());
    }

    public function testRecipientTypedInByTheUserIsKept(): void
    {
        $client = $this->loggedInClient();
        [$reservation, $template, $invoice] = $this->arrange();

        $crawler = $this->writeMailAndAttach($client, $reservation, $template, $invoice, 'someone@example.com');

        self::assertSame('someone@example.com', $crawler->filter('#template-to')->attr('value'));
        self::assertCount(0, $crawler->filter('#template-to + .form-text'));
    }

    private function writeMailAndAttach(KernelBrowser $client, Reservation $reservation, Template $template, Invoice $invoice, string $to): Crawler
    {
        $client->request('POST', '/correspondence/select/reservation', ['createNew' => 'true', 'reservationid' => $reservation->getId()]);
        self::assertResponseIsSuccessful();

        // Opening the mail prefills the booker's email.
        $crawler = $client->request('POST', '/reservation/select/template/'.$template->getId(), ['inProcess' => 'true']);
        self::assertResponseIsSuccessful();
        self::assertSame('guest@example.com', $crawler->filter('#template-to')->attr('value'));

        // "Add attachment" keeps what was typed so far, then the invoice is attached.
        $client->request('POST', '/reservation/select/template', ['inProcess' => 'true', 'templateId' => $template->getId(), 'to' => $to, 'subject' => 'Rechnung', 'msg' => 'Hallo']);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/correspondence/attachment/add', ['isInvoice' => 'true', 'id' => $invoice->getId()]);
        self::assertResponseIsSuccessful();

        $crawler = $client->request('POST', '/reservation/select/template/0', ['inProcess' => 'false']);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return array{Reservation, Template, Invoice} */
    private function arrange(): array
    {
        $em = $this->em();
        $reservation = null;
        foreach ($em->getRepository(Reservation::class)->findAll() as $candidate) {
            if (null !== $candidate->getBooker()) {
                $reservation = $candidate;
                break;
            }
        }
        self::assertInstanceOf(Reservation::class, $reservation);
        $booker = $reservation->getBooker();
        foreach ($booker->getCustomerAddresses() as $address) {
            $booker->removeCustomerAddress($address);
        }
        $address = (new CustomerAddresses())->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE')
            ->setEmail('guest@example.com')
            ->setInvoiceEmail('accounts@example.com');
        $booker->addCustomerAddress($address);
        $em->persist($address);

        $templateRepository = $em->getRepository(Template::class);
        self::assertInstanceOf(TemplateRepository::class, $templateRepository);
        $templates = $templateRepository->loadByTypeName(['TEMPLATE_RESERVATION_EMAIL']);
        self::assertNotEmpty($templates);

        $invoice = new Invoice();
        $invoice->setNumber('MAIL-'.bin2hex(random_bytes(4)));
        $invoice->setDate(new \DateTime('2026-09-01'));
        $invoice->setStatus(1);
        $invoice->setLastname('Novak');
        $invoice->setEmail('accounts@example.com');
        $em->persist($invoice);
        $em->flush();

        return [$reservation, $templates[0], $invoice];
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $this->em();

        $user = new User();
        $user->setUsername('mailrcpt_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Reservations');
        $user->setEmail(sprintf('mailrcpt+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setLastSeenVersion('99.99.99');
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS']);
        $user->setRoleEntities(null !== $role ? [$role] : []);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
