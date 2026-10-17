<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\AppSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The public address in the general settings, from which links for guests are built. */
final class PublicAddressSettingsTest extends WebTestCase
{
    public function testAdminStoresTheAddressWithoutTrailingSlash(): void
    {
        $client = $this->authenticatedClient();

        $this->submitAddress($client, 'https://fewohbee.example.com/');

        self::assertResponseRedirects('/settings/general');
        self::assertSame('https://fewohbee.example.com', $this->settings()->getPublicBaseUrl());
    }

    public function testAddressWithQueryStringIsRejected(): void
    {
        $client = $this->authenticatedClient();

        $this->submitAddress($client, 'https://fewohbee.example.com/?utm=x');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#app_settings_publicBaseUrl.is-invalid');
        self::assertNull($this->settings()->getPublicBaseUrl());
    }

    public function testLocalAddressIsStoredButFlagged(): void
    {
        $client = $this->authenticatedClient();

        $this->submitAddress($client, 'http://localhost:8000');
        $client->followRedirect();

        self::assertSelectorExists('.alert-warning');
        self::assertSame('http://localhost:8000', $this->settings()->getPublicBaseUrl());
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $em->clear();
                $this->settings()->setPublicBaseUrl(null);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    private function submitAddress(KernelBrowser $client, string $address): void
    {
        $crawler = $client->request('GET', '/settings/general');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="app_settings"]')->form();
        $form['app_settings[publicBaseUrl]'] = $address;
        $client->submit($form);
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
