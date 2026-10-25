<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The confirmation button in the reservation details dialog.
 */
final class ReservationConfirmationTest extends WebTestCase
{
    public function testThePreviewShowsMailAndPdfAndSendingRecordsItForEveryRoom(): void
    {
        $client = $this->authenticatedClient();
        $email = 'confirm-'.bin2hex(random_bytes(4)).'@example.com';
        $booker = $this->createBooker($email);
        $group = Uuid::v4();
        $first = $this->createReservation($booker, '+900 days', $group);
        $second = $this->createReservation($booker, '+900 days', $group);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $first->getId()));
        self::assertCount(1, $crawler->filter(sprintf('[data-url$="/reservation/%d/confirmation/preview"]', $first->getId())));
        self::assertCount(0, $crawler->filter('.dropdown-toggle .fa-paper-plane'), 'With one address there is nothing to choose.');
        self::assertCount(0, $crawler->filter('form[action$="/confirmation/send"]'), 'Sending only happens from the preview.');

        $crawler = $client->request('GET', sprintf('/reservation/%d/confirmation/preview', $first->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-body dl', $email);
        self::assertNotSame('', trim((string) $crawler->filter('iframe')->attr('srcdoc')));
        self::assertSame($email, $crawler->filter('form[action$="/confirmation/send"] input[name="email"]')->attr('value'));
        self::assertSame([], $this->mailsFor($first), 'The preview sends nothing.');

        $pdfLink = $crawler->filter('a[href*="/confirmation/preview/attachment/0"]');
        self::assertCount(1, $pdfLink);
        $client->request('GET', (string) $pdfLink->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());

        $form = $crawler->filter('form[action$="/confirmation/send"]');
        $token = (string) $form->filter('input[name="_token"]')->attr('value');
        $client->request('POST', sprintf('/reservation/%d/confirmation/send', $first->getId()), ['_token' => 'forged']);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->mailsFor($first));

        $client->request('POST', sprintf('/reservation/%d/confirmation/send', $first->getId()), ['_token' => $token]);
        self::assertResponseIsSuccessful();
        foreach ([$first, $second] as $reservation) {
            $mails = $this->mailsFor($reservation);
            self::assertCount(1, $mails);
            self::assertSame($email, $mails[0]->getRecipient());
        }
    }

    public function testWithSeveralAddressesTheChosenOneGetsTheConfirmation(): void
    {
        $client = $this->authenticatedClient();
        $private = 'confirm-'.bin2hex(random_bytes(4)).'@example.com';
        $work = 'confirm-work-'.bin2hex(random_bytes(4)).'@example.com';
        $booker = $this->createBooker($private);
        $address = (new CustomerAddresses())->setType('CUSTOMER_ADDRESS_TYPE_BUSINESS')->setEmail($work);
        $this->em()->persist($address);
        $booker->addCustomerAddress($address);
        $this->em()->flush();
        $reservation = $this->createReservation($booker, '+930 days', null);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        $choices = $crawler->filter('.dropdown-menu [data-url*="/confirmation/preview?email="]');
        self::assertSame([$private, $work], $choices->each(static fn ($choice) => trim($choice->text())));

        $crawler = $client->request('GET', (string) $choices->eq(1)->attr('data-url'));
        self::assertSelectorTextContains('.modal-body dl', $work);
        self::assertSame($work, $crawler->filter('form[action$="/confirmation/send"] input[name="email"]')->attr('value'));
        $token = (string) $crawler->filter('form[action$="/confirmation/send"] input[name="_token"]')->attr('value');

        $client->request('POST', sprintf('/reservation/%d/confirmation/send', $reservation->getId()), ['_token' => $token, 'email' => 'someone-else@example.com']);
        self::assertCount(0, $this->mailsFor($reservation), 'Only an address of the booker is used.');

        $client->request('POST', sprintf('/reservation/%d/confirmation/send', $reservation->getId()), ['_token' => $token, 'email' => $work]);
        $mails = $this->mailsFor($reservation);
        self::assertCount(1, $mails);
        self::assertSame($work, $mails[0]->getRecipient());
    }

    public function testWithoutAnAddressThePreviewSaysWhyInsteadOfOfferingToSend(): void
    {
        $client = $this->authenticatedClient();
        $reservation = $this->createReservation($this->createBooker(null), '+910 days', null);

        $crawler = $client->request('GET', sprintf('/reservation/%d/confirmation/preview', $reservation->getId()));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/confirmation/send"]'));
        self::assertSelectorTextContains('.modal-body .alert', 'keine E-Mail-Adresse');
    }

    public function testReadOnlyUsersCannotPreviewOrSend(): void
    {
        $client = self::createClient();
        $reservation = $this->createReservation($this->createBooker('ro-'.bin2hex(random_bytes(4)).'@example.com'), '+920 days', null);
        $user = new User();
        $user->setUsername('confirmation-ro-'.bin2hex(random_bytes(3)))->setFirstname('Read')->setLastname('Only')
            ->setEmail('confirmation-ro@example.com')->setPassword('not-used-for-login')->setActive(true);
        $user->setRole($this->em()->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']));
        $this->em()->persist($user);
        $this->em()->flush();
        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        self::assertCount(0, $crawler->filter('[data-url$="/confirmation/preview"]'));

        $client->request('GET', sprintf('/reservation/%d/confirmation/preview', $reservation->getId()));
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', sprintf('/reservation/%d/confirmation/preview/attachment/0', $reservation->getId()));
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', sprintf('/reservation/%d/confirmation/send', $reservation->getId()), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->mailsFor($reservation));
    }

    /** @return MailCorrespondence[] */
    private function mailsFor(Reservation $reservation): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(MailCorrespondence::class)->findBy(['reservation' => $reservation->getId()]);
    }

    private function createBooker(?string $email): Customer
    {
        $booker = new Customer();
        $booker->setSalutation('Herr');
        $booker->setLastname('Bestätigung');
        $address = new CustomerAddresses();
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setEmail($email);
        $booker->addCustomerAddress($address);
        $this->em()->persist($address);
        $this->em()->persist($booker);
        $this->em()->flush();

        return $booker;
    }

    private function createReservation(Customer $booker, string $arrival, ?Uuid $group): Reservation
    {
        $start = new \DateTime($arrival);
        $reservation = new Reservation();
        $reservation->setAppartment($this->em()->getRepository(Appartment::class)->findOneBy(['active' => true], ['id' => 'ASC']));
        $reservation->setReservationOrigin($this->em()->getRepository(ReservationOrigin::class)->findOneBy([], ['id' => 'ASC']));
        $reservation->setReservationStatus($this->em()->getRepository(ReservationStatus::class)->findOneBy([], ['id' => 'ASC']));
        $reservation->setPersons(1);
        $reservation->setStartDate($start);
        $reservation->setEndDate((clone $start)->modify('+2 days'));
        $reservation->setUuid(Uuid::v4());
        $reservation->setBookingGroupUuid($group);
        $reservation->setBooker($booker);
        $this->em()->persist($reservation);
        $this->em()->flush();

        return $reservation;
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin'])
            ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
