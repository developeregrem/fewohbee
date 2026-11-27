<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\FileCorrespondence;
use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\Template;
use App\Entity\User;
use App\Entity\Workflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Uid\Uuid;

/**
 * The confirmation button in the reservation details dialog, which opens the mail editor with
 * the template and attachments from the general settings.
 */
final class ReservationConfirmationTest extends WebTestCase
{
    /** @var list<int> */
    private array $workflowIds = [];

    public function testTheButtonOpensTheEditorForTheWholeBookingAndSendingRecordsItForEveryRoom(): void
    {
        $client = $this->authenticatedClient();
        [$mailTemplate, $pdfTemplate] = $this->configure();
        $email = 'confirm-'.bin2hex(random_bytes(4)).'@example.com';
        $booker = $this->createBooker($email);
        $group = Uuid::v4();
        $first = $this->createReservation($booker, '+900 days', $group);
        $second = $this->createReservation($booker, '+900 days', $group);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $first->getId()));
        $button = $crawler->filter('[data-action="reservations#openConfirmationAction"]');
        self::assertCount(1, $button, 'With one address there is nothing to choose.');
        self::assertSame(sprintf('/reservation/%d/confirmation', $first->getId()), $button->attr('data-url'));

        $crawler = $this->open($client, $button);
        self::assertResponseIsSuccessful();
        self::assertSame($email, $crawler->filter('#template-to')->attr('value'));
        self::assertSame((string) $mailTemplate->getId(), $crawler->filter('#template-form input[name="templateId"]')->attr('value'));
        self::assertCount(1, $crawler->filter('#template-form .list-group-item'), 'The PDF from the settings is attached.');
        self::assertCount(1, $this->filesFor($first, $pdfTemplate));
        self::assertCount(1, $this->filesFor($second, $pdfTemplate));
        self::assertSame([], $this->mailsFor($first), 'Opening the editor sends nothing.');

        $client->request('POST', '/correspondence/email/send', [
            '_csrf_token' => $crawler->filter('#template-form input[name="_csrf_token"]')->attr('value'),
            'templateId' => $mailTemplate->getId(),
            'to' => $email,
            'subject' => 'Bestätigung',
            'msg' => '<p>Hallo</p>',
        ]);
        self::assertResponseIsSuccessful();
        foreach ([$first, $second] as $reservation) {
            $mails = $this->mailsFor($reservation);
            self::assertCount(1, $mails);
            self::assertSame($email, $mails[0]->getRecipient());
            self::assertCount(1, $mails[0]->getChildren(), 'The mail keeps the attachment of its room.');
        }
    }

    public function testSendingRightAwayMailsTheWholeBookingWithoutTheEditor(): void
    {
        $client = $this->authenticatedClient();
        [, $pdfTemplate] = $this->configure();
        $this->settings()->setConfirmationSendImmediately(true);
        $this->em()->flush();
        $email = 'confirm-now-'.bin2hex(random_bytes(4)).'@example.com';
        $booker = $this->createBooker($email);
        $group = Uuid::v4();
        $first = $this->createReservation($booker, '+950 days', $group);
        $second = $this->createReservation($booker, '+950 days', $group);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $first->getId()));
        $crawler = $this->open($client, $crawler->filter('[data-action="reservations#openConfirmationAction"]'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#template-form'));
        self::assertSelectorTextContains('.alert-success', 'Bestätigung wurde verschickt');
        foreach ([$first, $second] as $reservation) {
            $mails = $this->mailsFor($reservation);
            self::assertCount(1, $mails);
            self::assertSame($email, $mails[0]->getRecipient());
            self::assertCount(1, $this->filesFor($reservation, $pdfTemplate));
        }
    }

    public function testSendingRightAwayWithoutAnAddressSaysWhy(): void
    {
        $client = $this->authenticatedClient();
        [, $pdfTemplate] = $this->configure();
        $this->settings()->setConfirmationSendImmediately(true);
        $this->em()->flush();
        $reservation = $this->createReservation($this->createBooker(null), '+960 days', null);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        $this->open($client, $crawler->filter('[data-action="reservations#openConfirmationAction"]'));

        self::assertSelectorTextContains('.alert-warning', 'keine E-Mail-Adresse');
        self::assertSame([], $this->mailsFor($reservation));
        self::assertSame([], $this->filesFor($reservation, $pdfTemplate));
    }

    /** A booking imported from a portal calendar has no booker, so there is nobody to confirm to. */
    public function testWithoutABookerTheButtonIsDisabledAndOpeningItSaysWhy(): void
    {
        $client = $this->authenticatedClient();
        [, $pdfTemplate] = $this->configure();
        $reservation = $this->createReservation(null, '+970 days', null);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        $button = $crawler->filter('[data-action="reservations#openConfirmationAction"]');
        self::assertCount(1, $button);
        self::assertNotNull($button->attr('disabled'));

        foreach ([false, true] as $sendsImmediately) {
            $this->settings()->setConfirmationSendImmediately($sendsImmediately);
            $this->em()->flush();
            $crawler = $this->open($client, $button);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.alert-warning', 'kein Bucher');
            self::assertCount(0, $crawler->filter('#template-form'));
        }
        self::assertSame([], $this->mailsFor($reservation));
        self::assertSame([], $this->filesFor($reservation, $pdfTemplate));
    }

    public function testWithSeveralAddressesThePickedOneIsTheRecipient(): void
    {
        $client = $this->authenticatedClient();
        $this->configure();
        $private = 'confirm-'.bin2hex(random_bytes(4)).'@example.com';
        $work = 'confirm-work-'.bin2hex(random_bytes(4)).'@example.com';
        $booker = $this->createBooker($private);
        $address = (new CustomerAddresses())->setType('CUSTOMER_ADDRESS_TYPE_BUSINESS')->setEmail($work);
        $this->em()->persist($address);
        $booker->addCustomerAddress($address);
        $this->em()->flush();
        $reservation = $this->createReservation($booker, '+930 days', null);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        $choices = $crawler->filter('.dropdown-menu [data-action="reservations#openConfirmationAction"]');
        self::assertSame([$private, $work], $choices->each(static fn (Crawler $choice): ?string => $choice->attr('data-email')));

        $crawler = $this->open($client, $choices->eq(1));
        self::assertSame($work, $crawler->filter('#template-to')->attr('value'));

        $crawler = $this->open($client, $choices->eq(1), 'someone-else@example.com');
        self::assertSame($private, $crawler->filter('#template-to')->attr('value'), 'Only an address of the booker is prefilled.');
    }

    public function testWithoutAConfiguredTemplateThereIsNoButton(): void
    {
        $client = $this->authenticatedClient();
        $reservation = $this->createReservation($this->createBooker('none-'.bin2hex(random_bytes(4)).'@example.com'), '+910 days', null);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        self::assertCount(0, $crawler->filter('[data-action="reservations#openConfirmationAction"]'));

        $client->request('POST', sprintf('/reservation/%d/confirmation', $reservation->getId()), ['_token' => $this->token($client, $reservation)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAForgedTokenPreparesNothing(): void
    {
        $client = $this->authenticatedClient();
        [, $pdfTemplate] = $this->configure();
        $reservation = $this->createReservation($this->createBooker('forged-'.bin2hex(random_bytes(4)).'@example.com'), '+940 days', null);

        $crawler = $client->request('POST', sprintf('/reservation/%d/confirmation', $reservation->getId()), ['_token' => 'forged']);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#template-form'));
        self::assertSame([], $this->filesFor($reservation, $pdfTemplate));
    }

    public function testReadOnlyUsersHaveNoButtonAndCannotOpenIt(): void
    {
        $client = self::createClient();
        [, $pdfTemplate] = $this->configure();
        $reservation = $this->createReservation($this->createBooker('ro-'.bin2hex(random_bytes(4)).'@example.com'), '+920 days', null);
        $user = new User();
        $user->setUsername('confirmation-ro-'.bin2hex(random_bytes(3)))->setFirstname('Read')->setLastname('Only')
            ->setEmail('confirmation-ro@example.com')->setPassword('not-used-for-login')->setActive(true);
        $user->setRole($this->em()->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']));
        $this->em()->persist($user);
        $this->em()->flush();
        $client->loginUser($user, 'main');

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        self::assertCount(0, $crawler->filter('[data-action="reservations#openConfirmationAction"]'));

        $client->request('POST', sprintf('/reservation/%d/confirmation', $reservation->getId()), ['_token' => 'any']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->filesFor($reservation, $pdfTemplate));
    }

    public function testAdminChoosesTemplateAndAttachmentsInTheGeneralSettings(): void
    {
        $client = $this->authenticatedClient();
        $mailTemplate = $this->templateOfType('TEMPLATE_RESERVATION_EMAIL');
        $pdfTemplate = $this->templateOfType('TEMPLATE_RESERVATION_PDF');

        $crawler = $client->request('GET', '/settings/general');
        $form = $crawler->filter('form[name="app_settings"]')->form();
        $form['app_settings[confirmationTemplate]'] = (string) $mailTemplate->getId();
        $form['app_settings[confirmationAttachments]'] = [(string) $pdfTemplate->getId()];
        $sendImmediately = $form['app_settings[confirmationSendImmediately]'];
        self::assertInstanceOf(ChoiceFormField::class, $sendImmediately);
        $sendImmediately->tick();
        $client->submit($form);

        self::assertResponseRedirects('/settings/general');
        $settings = $this->settings();
        self::assertSame($mailTemplate->getId(), $settings->getConfirmationTemplate()?->getId());
        self::assertSame([$pdfTemplate->getId()], $settings->getConfirmationAttachments()->map(static fn (Template $template): ?int => $template->getId())->getValues());
        self::assertTrue($settings->isConfirmationSendImmediately());
    }

    public function testSendingRightAwayLetsAnAutomationConfirmEveryRoomOfTheBooking(): void
    {
        $client = $this->authenticatedClient();
        $this->configure();
        $this->settings()->setConfirmationSendImmediately(true);
        $this->em()->flush();
        $confirmed = $this->automateStatusAfterConfirmation();
        $booker = $this->createBooker('confirm-status-'.bin2hex(random_bytes(4)).'@example.com');
        $group = Uuid::v4();
        $first = $this->createReservation($booker, '+980 days', $group);
        $second = $this->createReservation($booker, '+980 days', $group);

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $first->getId()));
        $this->open($client, $crawler->filter('[data-action="reservations#openConfirmationAction"]'));

        self::assertSelectorExists('.alert-success');
        self::assertSame([$confirmed, $confirmed], $this->statusIdsOf($first, $second));
    }

    public function testSendingFromTheEditorLetsAnAutomationConfirmEveryRoomButOtherMailsDoNot(): void
    {
        $client = $this->authenticatedClient();
        [$mailTemplate] = $this->configure();
        $confirmed = $this->automateStatusAfterConfirmation();
        $email = 'confirm-editor-'.bin2hex(random_bytes(4)).'@example.com';
        $group = Uuid::v4();
        $first = $this->createReservation($this->createBooker($email), '+990 days', $group);
        $second = $this->createReservation($first->getBooker(), '+990 days', $group);
        $initial = $this->statusIdsOf($first, $second);
        $otherTemplate = $this->em()->getRepository(Template::class)->createQueryBuilder('t')
            ->join('t.templateType', 'tt')
            ->where('tt.name = :type')->andWhere('t.id != :confirmation')
            ->setParameter('type', 'TEMPLATE_RESERVATION_EMAIL')->setParameter('confirmation', $mailTemplate->getId())
            ->setMaxResults(1)->getQuery()->getSingleResult();

        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $first->getId()));
        $crawler = $this->open($client, $crawler->filter('[data-action="reservations#openConfirmationAction"]'));
        $send = static fn (Template $template): array => [
            '_csrf_token' => $crawler->filter('#template-form input[name="_csrf_token"]')->attr('value'),
            'templateId' => $template->getId(),
            'to' => $email,
            'subject' => 'Bestätigung',
            'msg' => '<p>Hallo</p>',
        ];

        $client->request('POST', '/correspondence/email/send', $send($otherTemplate));
        self::assertResponseIsSuccessful();
        self::assertSame($initial, $this->statusIdsOf($first, $second), 'Another mail confirms nothing.');

        $client->request('POST', '/correspondence/email/send', $send($mailTemplate));
        self::assertResponseIsSuccessful();
        self::assertSame([$confirmed, $confirmed], $this->statusIdsOf($first, $second));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $this->clearSettings();
                foreach ($this->workflowIds as $id) {
                    $em->getConnection()->executeStatement('DELETE FROM workflow_logs WHERE workflow_id = ?', [$id]);
                    $em->getConnection()->executeStatement('DELETE FROM workflows WHERE id = ?', [$id]);
                }
            }
        }
        parent::tearDown();
    }

    /**
     * An automation that sets a booking to the first blocking status once its confirmation went
     * out. The test reservations start with the first status, which is not blocking.
     *
     * @return int the id of the status it sets
     */
    private function automateStatusAfterConfirmation(): int
    {
        $status = $this->em()->getRepository(ReservationStatus::class)->findOneBy(['isBlocking' => true], ['id' => 'ASC'])
            ?? throw new \RuntimeException('No blocking status in the sample data.');
        $workflow = (new Workflow())
            ->setName('Bestätigung → Status '.bin2hex(random_bytes(3)))
            ->setTriggerType('reservation.confirmation_sent')
            ->setActionType('change_reservation_status')
            ->setActionConfig(['statusId' => (string) $status->getId()]);
        $this->em()->persist($workflow);
        $this->em()->flush();
        $this->workflowIds[] = (int) $workflow->getId();

        return (int) $status->getId();
    }

    /** @return list<?int> */
    private function statusIdsOf(Reservation ...$reservations): array
    {
        $this->em()->clear();

        return array_map(fn (Reservation $reservation): ?int => $this->em()->find(Reservation::class, $reservation->getId())?->getReservationStatus()?->getId(), array_values($reservations));
    }

    /**
     * Sets a reservation mail and PDF from the sample data as the confirmation in the settings.
     *
     * @return array{Template, Template}
     */
    private function configure(): array
    {
        $mailTemplate = $this->templateOfType('TEMPLATE_RESERVATION_EMAIL');
        $pdfTemplate = $this->templateOfType('TEMPLATE_RESERVATION_PDF');
        $this->settings()->setConfirmationTemplate($mailTemplate)->addConfirmationAttachment($pdfTemplate);
        $this->em()->flush();

        return [$mailTemplate, $pdfTemplate];
    }

    /** Posts the button as reservations#openConfirmationAction does. */
    private function open(KernelBrowser $client, Crawler $button, ?string $email = null): Crawler
    {
        return $client->request('POST', (string) $button->attr('data-url'), [
            '_token' => $button->attr('data-token'),
            'email' => $email ?? $button->attr('data-email') ?? '',
        ]);
    }

    /** A valid token for the button, taken while a template is configured. */
    private function token(KernelBrowser $client, Reservation $reservation): string
    {
        $this->configure();
        $crawler = $client->request('GET', sprintf('/reservation/get/%d', $reservation->getId()));
        $token = (string) $crawler->filter('[data-action="reservations#openConfirmationAction"]')->attr('data-token');
        $this->clearSettings();

        return $token;
    }

    private function clearSettings(): void
    {
        $settings = $this->settings();
        $settings->setConfirmationTemplate(null)->setConfirmationSendImmediately(false);
        $settings->getConfirmationAttachments()->clear();
        $this->em()->flush();
    }

    private function templateOfType(string $typeName): Template
    {
        return $this->em()->getRepository(Template::class)->createQueryBuilder('t')
            ->join('t.templateType', 'tt')
            ->where('tt.name = :type')
            ->andWhere('t.baseTemplate IS NULL')
            ->setParameter('type', $typeName)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
    }

    /** @return MailCorrespondence[] */
    private function mailsFor(Reservation $reservation): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(MailCorrespondence::class)->findBy(['reservation' => $reservation->getId()]);
    }

    /** @return FileCorrespondence[] */
    private function filesFor(Reservation $reservation, Template $template): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(FileCorrespondence::class)->findBy(['reservation' => $reservation->getId(), 'template' => $template->getId()]);
    }

    private function settings(): AppSettings
    {
        return $this->em()->getRepository(AppSettings::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('Settings row missing.');
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

    private function createReservation(?Customer $booker, string $arrival, ?Uuid $group): Reservation
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
