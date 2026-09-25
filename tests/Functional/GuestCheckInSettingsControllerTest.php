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
        self::assertTrue($workflows->findOneBy(['systemCode' => 'notify_guest_checkin'])?->isEnabled());
        self::assertFalse($workflows->findOneBy(['systemCode' => 'example_guest_checkin_invitation'])?->isEnabled());
    }

    public function testAnonymousVisitorIsSentToTheLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/settings/guest-checkin');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $em->clear();
                $this->config()->setEnabled(false)->setIntroText(null)
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
            if (true === $value && $formField instanceof ChoiceFormField) {
                $formField->tick();
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
