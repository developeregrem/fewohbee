<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The languages offered to guests (general settings) and the translation inputs they bring along. */
final class GuestLanguageSettingsTest extends WebTestCase
{
    public function testUntickingALanguageLimitsTheGuestLanguages(): void
    {
        $client = $this->authenticatedClient();

        $this->submitGuestLocales($client, []);

        self::assertResponseRedirects('/settings/general');
        self::assertSame(['de'], $this->settings()->getGuestLocales(), 'The installation language always stays.');
    }

    public function testTickingEveryLanguageAlsoOffersLaterLanguages(): void
    {
        $client = $this->authenticatedClient();
        $this->settings()->setGuestLocales(['de']);
        $this->em()->flush();

        /** @var list<string> $enabled */
        $enabled = self::getContainer()->getParameter('kernel.enabled_locales');
        $this->submitGuestLocales($client, array_values(array_diff($enabled, ['de'])));

        self::assertResponseRedirects('/settings/general');
        self::assertNull($this->settings()->getGuestLocales());
    }

    public function testSalutationsAreSavedWithTheirWordingPerLanguage(): void
    {
        $client = $this->authenticatedClient();

        $crawler = $client->request('GET', '/settings/general');
        // The built-in entries arrive in German with their English wording filled in.
        self::assertSame('Frau', $crawler->filter('input[name="app_settings[customerSalutations][0][salutation]"]')->attr('value'));
        self::assertSame('Ms', $crawler->filter('input[name="app_settings[customerSalutations][0][en]"]')->attr('value'));

        $form = $crawler->filter('form[name="app_settings"]')->form();
        $values = $form->getPhpValues();
        $values['app_settings']['customerSalutations'] = [
            ['salutation' => 'Frau', 'en' => 'Mrs'],
            ['salutation' => 'Divers', 'en' => ''],
        ];
        $client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/settings/general');
        $settings = $this->settings();
        self::assertSame(['Frau', 'Divers'], $settings->getCustomerSalutations());
        self::assertSame(['Frau' => ['en' => 'Mrs']], $settings->getCustomerSalutationTranslations());
    }

    public function testTranslationInputsFollowTheGuestLanguages(): void
    {
        $client = $this->authenticatedClient();

        $client->request('GET', '/settings/category/new');
        self::assertSelectorExists('input[name="room_category[translations][en][name]"][lang="en"]');
        self::assertSelectorExists('textarea[name="room_category[translations][en][details]"]');

        $this->settings()->setGuestLocales(['de']);
        $this->em()->flush();

        $client->request('GET', '/settings/category/new');
        self::assertSelectorNotExists('[name^="room_category[translations]"]');
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $this->settings()->setGuestLocales(null)
                    ->setCustomerSalutations(['Ms', 'Mr', 'Family'])
                    ->setCustomerSalutationTranslations(null);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    /** @param list<string> $locales the languages ticked besides the installation language */
    private function submitGuestLocales(KernelBrowser $client, array $locales): void
    {
        $crawler = $client->request('GET', '/settings/general');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="app_settings"]')->form();
        $values = $form->getPhpValues();
        $values['app_settings']['guestLocales'] = $locales;
        $client->request('POST', $form->getUri(), $values);
    }

    private function settings(): AppSettings
    {
        $this->em()->clear();

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
