<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationBookPeriodTest extends WebTestCase
{
    /** @param array<string, string> $query */
    #[DataProvider('periods')]
    public function testPeriodFallbackAndSessionSelection(array $query, string $expectedStart, string $expectedEnd): void
    {
        $client = $this->authenticatedClient();
        $start = (new \DateTimeImmutable($expectedStart))->format('Y-m-d');
        $end = (new \DateTimeImmutable($expectedEnd))->format('Y-m-d');

        $client->request('GET', '/registrationbook/showadd/reservations', $query);

        self::assertResponseIsSuccessful();
        self::assertInputValueSame('start', $start);
        self::assertInputValueSame('end', $end);

        // Reopening the modal without dates must retain the resolved selection.
        $client->request('GET', '/registrationbook/showadd/reservations');

        self::assertResponseIsSuccessful();
        self::assertInputValueSame('start', $start);
        self::assertInputValueSame('end', $end);
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function periods(): iterable
    {
        yield 'malformed start' => [
            ['start' => 'not-a-date', 'end' => 'today'], '-30 days', 'today',
        ];
        yield 'malformed end' => [
            ['start' => '-10 days', 'end' => 'not-a-date'], '-10 days', 'today',
        ];
        yield 'both dates malformed' => [
            ['start' => 'not-a-date', 'end' => 'not-a-date'], '-30 days', 'today',
        ];
        yield 'missing dates' => [[], '-30 days', 'today'];
        yield 'valid dates' => [
            ['start' => '2031-05-01', 'end' => '2031-05-03'], '2031-05-01', '2031-05-03',
        ];
        yield 'reversed dates' => [
            ['start' => '2031-05-03', 'end' => '2031-05-01'], '2031-05-01', '2031-05-03',
        ];
    }

    public function testAnAnonymousRequestStillRequiresAuthentication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/registrationbook/showadd/reservations', ['start' => 'not-a-date']);

        self::assertResponseRedirects('/login');
    }

    private function authenticatedClient(): KernelBrowser
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);
        $client->loginUser($user, 'main');

        return $client;
    }
}
