<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileCorrespondence;
use App\Entity\Reservation;
use App\Entity\Role;
use App\Entity\Template;
use App\Entity\TemplateType;
use App\Entity\User;
use App\Service\ReservationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CorrespondenceFileSaveTest extends WebTestCase
{
    private KernelBrowser $client;
    private int $templateId;
    private ?int $readOnlyUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'main');

        $type = $this->em()->getRepository(TemplateType::class)->findOneBy(['name' => 'TEMPLATE_FILE_PDF']);
        self::assertInstanceOf(TemplateType::class, $type);
        $template = new Template();
        $template->setName('Correspondence regression file');
        $template->setTemplateType($type);
        $template->setText('<p>Correspondence regression content</p>');
        $template->setIsDefault(false);
        $this->em()->persist($template);
        $this->em()->flush();
        $this->templateId = $template->getId();
    }

    #[DataProvider('selectedReservations')]
    public function testSavesAFileForEverySelectedReservation(int $count, bool $asAttachment): void
    {
        $fields = $this->prepareForm($count, $asAttachment);
        $session = $this->session();
        $reservationIds = $session->get(ReservationService::SESSION_SELECTED_RESERVATIONS);
        $session->save();

        $this->client->request('POST', '/correspondence/file/save', $fields);

        self::assertResponseIsSuccessful();
        $files = $this->files();
        self::assertCount($count, $files);
        self::assertSame($reservationIds, array_map(static fn (FileCorrespondence $file): int => $file->getReservation()->getId(), $files));
        foreach ($files as $file) {
            self::assertSame($fields['subject'], $file->getName());
            self::assertSame($fields['subject'], $file->getFileName());
            self::assertSame($fields['msg'], $file->getText());
        }

        $session = $this->session();
        if ($asAttachment) {
            self::assertSelectorExists('.alert-success');
            self::assertSelectorExists('[data-is-attachment="true"]');
            // Grouped documents keep the existing shared attachment reference.
            self::assertSame([array_fill_keys($reservationIds, $files[$count - 1]->getId())], $session->get('templateAttachmentIds'));
        } else {
            self::assertSame('', trim((string) $this->client->getResponse()->getContent()));
            self::assertSame([], $session->get('templateAttachmentIds'));
        }
        $session->save();
    }

    /** @return iterable<string, array{int, bool}> */
    public static function selectedReservations(): iterable
    {
        yield 'one reservation' => [1, false];
        yield 'two reservations' => [2, false];
        yield 'attachment for one reservation' => [1, true];
        yield 'attachment for two reservations' => [2, true];
    }

    #[DataProvider('emptySelections')]
    public function testAnEmptySelectionIsRejectedWithoutSavingOrAddingAnAttachment(bool $asAttachment, bool $removeSelection): void
    {
        $fields = $this->prepareForm(1, $asAttachment);
        $session = $this->session();
        if ($removeSelection) {
            $session->remove(ReservationService::SESSION_SELECTED_RESERVATIONS);
        } else {
            $session->set(ReservationService::SESSION_SELECTED_RESERVATIONS, []);
        }
        $attachments = $session->get('templateAttachmentIds');
        $session->save();

        $this->client->request('POST', '/correspondence/file/save', $fields);

        self::assertResponseIsSuccessful();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertSelectorTextContains('.alert-warning', $translator->trans('templates.savefile.reservations.required'));
        self::assertSelectorNotExists('.alert-success');
        self::assertSelectorNotExists('[data-is-attachment]');
        self::assertSame([], $this->files());
        $session = $this->session();
        self::assertSame($attachments, $session->get('templateAttachmentIds'));
        $session->save();
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function emptySelections(): iterable
    {
        yield 'missing selection' => [false, true];
        yield 'empty selection' => [false, false];
        yield 'attachment with missing selection' => [true, true];
        yield 'attachment with empty selection' => [true, false];
    }

    public function testAnInvalidCsrfTokenDoesNotSaveAFile(): void
    {
        $fields = $this->prepareForm(1, true);
        $fields['_csrf_token'] = 'invalid-token';

        $this->client->request('POST', '/correspondence/file/save', $fields);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-warning');
        self::assertSame([], $this->files());
        $session = $this->session();
        self::assertSame([], $session->get('templateAttachmentIds'));
        $session->save();
    }

    public function testAReadOnlyUserCannotSaveAFile(): void
    {
        $fields = $this->prepareForm(1, false);
        $role = $this->em()->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS_RO']);
        self::assertInstanceOf(Role::class, $role);
        $user = new User();
        $user->setUsername('correspondence-readonly-'.bin2hex(random_bytes(4)));
        $user->setFirstname('Correspondence');
        $user->setLastname('Read only');
        $user->setEmail('correspondence-readonly@example.com');
        $user->setActive(true);
        $user->setRoleEntities([$role]);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'ChangeMe123!'));
        $this->em()->persist($user);
        $this->em()->flush();
        $this->readOnlyUserId = $user->getId();
        $this->client->loginUser($user, 'main');

        $this->client->request('POST', '/correspondence/file/save', $fields);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->files());
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel && isset($this->templateId)) {
            $em = $this->em();
            foreach ($this->files() as $file) {
                $em->remove($file);
            }
            $template = $em->find(Template::class, $this->templateId);
            if (null !== $template) {
                $em->remove($template);
            }
            if (null !== $this->readOnlyUserId) {
                $user = $em->find(User::class, $this->readOnlyUserId);
                if (null !== $user) {
                    $em->remove($user);
                }
            }
            $em->flush();
        }
        parent::tearDown();
    }

    /** @return array{subject: string, msg: string, templateId: string, _csrf_token: string} */
    private function prepareForm(int $count, bool $asAttachment): array
    {
        $reservations = $this->em()->getRepository(Reservation::class)->findBy([], ['id' => 'ASC'], $count);
        self::assertCount($count, $reservations);
        foreach ($reservations as $index => $reservation) {
            $this->client->request('POST', '/correspondence/select/reservation', [
                'createNew' => 0 === $index ? 'true' : 'false',
                'reservationid' => (string) $reservation->getId(),
            ]);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('POST', '/reservation/select/template', ['inProcess' => 'false']);
        self::assertResponseIsSuccessful();
        if ($asAttachment) {
            $emailType = $this->em()->getRepository(TemplateType::class)->findOneBy(['name' => 'TEMPLATE_RESERVATION_EMAIL']);
            $emailTemplate = $this->em()->getRepository(Template::class)->findOneBy(['templateType' => $emailType]);
            self::assertInstanceOf(Template::class, $emailTemplate);
            $this->client->request('POST', '/reservation/select/template', [
                'inProcess' => 'true',
                'templateId' => (string) $emailTemplate->getId(),
            ]);
            self::assertResponseIsSuccessful();
        }
        $crawler = $this->client->request('POST', '/reservation/select/template/'.$this->templateId, [
            'inProcess' => $asAttachment ? 'true' : 'false',
        ]);
        self::assertResponseIsSuccessful();

        return [
            'subject' => 'Saved correspondence file',
            'msg' => '<p>Saved correspondence content</p>',
            'templateId' => (string) $this->templateId,
            '_csrf_token' => (string) $crawler->filter('input[name="_csrf_token"]')->attr('value'),
        ];
    }

    /** @return array<int, FileCorrespondence> */
    private function files(): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(FileCorrespondence::class)->findBy(['template' => $this->templateId], ['id' => 'ASC']);
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
