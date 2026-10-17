<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\OnlineBookingConfig;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The general online booking settings form; limits and booking rules have their own tests. */
final class OnlineBookingSettingsControllerTest extends WebTestCase
{
    public function testCommentPlaceholderIsStoredAndClearedAgain(): void
    {
        $client = $this->authenticatedClient();

        $this->submitSettings($client, '  Wann reist du voraussichtlich an?  ');

        self::assertResponseRedirects('/settings/online-booking');
        self::assertSame('Wann reist du voraussichtlich an?', $this->config()->getCommentPlaceholder());

        $this->submitSettings($client, '   ');

        self::assertResponseRedirects('/settings/online-booking');
        self::assertNull($this->config()->getCommentPlaceholder());
    }

    public function testCommentPlaceholderLongerThanTheColumnIsRefused(): void
    {
        $client = $this->authenticatedClient();

        $this->submitSettings($client, str_repeat('x', 256));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->config()->getCommentPlaceholder());
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $this->config()->setCommentPlaceholder(null);
                $em->flush();
            }
        }
        parent::tearDown();
    }

    private function submitSettings(KernelBrowser $client, string $placeholder): void
    {
        $crawler = $client->request('GET', '/settings/online-booking');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="online_booking_config"]')->form();
        $form['online_booking_config[commentPlaceholder]'] = $placeholder;
        $client->submit($form);
    }

    private function config(): OnlineBookingConfig
    {
        $this->em()->clear();

        return $this->em()->getRepository(OnlineBookingConfig::class)->findOneBy([], ['id' => 'ASC']) ?? throw new \RuntimeException('Config row missing.');
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
