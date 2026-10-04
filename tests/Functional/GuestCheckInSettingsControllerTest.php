<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\Enum\GuestCheckInFieldMode;
use App\Entity\GuestCheckInConfig;
use App\Entity\User;
use App\Entity\Workflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

final class GuestCheckInSettingsControllerTest extends WebTestCase
{
    public function testEnablingWithoutPublicAddressIsRefused(): void
    {
        $client = $this->authenticatedClient();

        $this->submitSettings($client, ['guest_check_in_config[enabled]' => true]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->config()->isEnabled());
    }

    public function testAdminEnablesTheCheckInAndChoosesFields(): void
    {
        $client = $this->authenticatedClient();
        $this->setPublicAddress('https://fewohbee.example.com');

        $this->submitSettings($client, [
            'guest_check_in_config[enabled]' => true,
            'guest_check_in_config[idDocumentMode]' => 'required',
            'guest_check_in_config[companionsMode]' => 'hidden',
            'guest_check_in_config[introText]' => 'Schön, dass du kommst!',
        ]);

        self::assertResponseRedirects('/settings/guest-checkin');
        $config = $this->config();
        self::assertTrue($config->isEnabled());
        self::assertSame('required', $config->getIdDocumentMode()->value);
        self::assertSame('hidden', $config->getCompanionsMode()->value);
        self::assertSame('Schön, dass du kommst!', $config->getIntroText());

        $workflows = $this->em()->getRepository(Workflow::class);
        self::assertNull($workflows->findOneBy(['systemCode' => 'notify_guest_checkin']), 'Reviews are a derived notification now.');
        self::assertFalse($workflows->findOneBy(['systemCode' => 'example_guest_checkin_invitation'])?->isEnabled());

        // The disabled example does not count as an invitation.
        $client->request('GET', '/settings/guest-checkin');
        self::assertSelectorTextContains('body', 'Noch keine automatische Einladung aktiv');
        self::assertSelectorTextContains('select#guest_check_in_config_companionsMode', 'Nur den Hauptgast erfassen');
    }

    public function testPreviewShowsTheGuestPageWithSampleDataAndCannotSend(): void
    {
        $client = $this->authenticatedClient();

        $client->request('GET', '/settings/guest-checkin/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="guest_check_in"][action="#"]');
        self::assertSelectorExists('form[name="guest_check_in"] button[type="submit"][disabled]');
        self::assertSelectorNotExists('.fhb-gci-lang');

        $client->request('GET', '/settings/guest-checkin/preview', ['view' => 'stay']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[name="guest_check_in"]');
        self::assertSelectorTextContains('.fhb-gci-checklist', 'Erika Mustermann');
        self::assertSelectorTextContains('.fhb-gci-checklist', '16:00');
    }

    public function testAdminCanTurnOptionalServicesOnAndOff(): void
    {
        $client = $this->authenticatedClient();

        $this->submitSettings($client, ['guest_check_in_config[extrasEnabled]' => false]);
        self::assertResponseRedirects('/settings/guest-checkin');
        self::assertFalse($this->config()->isExtrasEnabled());

        $this->submitSettings($client, ['guest_check_in_config[extrasEnabled]' => true]);
        self::assertResponseRedirects('/settings/guest-checkin');
        self::assertTrue($this->config()->isExtrasEnabled());
    }

    public function testPublicAddressHintAppearsOnlyWhenAddressIsMissing(): void
    {
        $client = $this->authenticatedClient();
        $this->appSettings()->setPublicBaseUrl(null);
        $this->em()->flush();

        $client->request('GET', '/settings/guest-checkin');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Es ist noch keine öffentliche Adresse hinterlegt.', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Öffentliche Adresse in den allgemeinen Einstellungen hinterlegen', (string) $client->getResponse()->getContent());

        $this->setPublicAddress('https://fewohbee.example.com');
        $client->request('GET', '/settings/guest-checkin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('https://fewohbee.example.com', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Es ist noch keine öffentliche Adresse hinterlegt.', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Öffentliche Adresse in den allgemeinen Einstellungen hinterlegen', (string) $client->getResponse()->getContent());
    }

    public function testAnonymousVisitorIsSentToTheLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/settings/guest-checkin');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));

        $client->request('GET', '/settings/guest-checkin/preview');
        self::assertResponseRedirects();
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $em->clear();
                $this->config()->setEnabled(false)->setIntroText(null)
                    ->setExtrasEnabled(true)
                    ->setIdDocumentMode(GuestCheckInFieldMode::OPTIONAL)
                    ->setCompanionsMode(GuestCheckInFieldMode::OPTIONAL);
                $em->getConnection()->executeStatement("DELETE FROM workflows WHERE system_code IN ('notify_guest_checkin', 'example_guest_checkin_invitation')");
                $this->appSettings()->setPublicBaseUrl(null);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $values */
    private function submitSettings(KernelBrowser $client, array $values): void
    {
        $crawler = $client->request('GET', '/settings/guest-checkin');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="guest_check_in_config"]')->form();
        foreach ($values as $field => $value) {
            $formField = $form[$field];
            if (\is_bool($value) && $formField instanceof ChoiceFormField) {
                $value ? $formField->tick() : $formField->untick();
            } else {
                $form[$field] = $value;
            }
        }
        $client->submit($form);
    }

    private function setPublicAddress(string $url): void
    {
        $this->appSettings()->setPublicBaseUrl($url);
        $this->em()->flush();
    }

    private function config(): GuestCheckInConfig
    {
        $this->em()->clear();

        return $this->em()->getRepository(GuestCheckInConfig::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('Config row missing.');
    }

    private function appSettings(): AppSettings
    {
        return $this->em()->getRepository(AppSettings::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('Settings row missing.');
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
        $client->loginUser($admin, 'main');

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
