<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Covers HTTP validation of reservation periods before availability queries run.
 */
final class ReservationPeriodControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testExcessiveReservationPeriodReturnsTranslated422(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getAdminUser(), 'main');

        $client->request('POST', '/reservation/appartments/available/get', [
            'from' => '2026-09-12',
            'end' => '4026-09-13',
            'object' => 'all',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'Der gewählte Reservierungszeitraum ist zu lang.',
            trim((string) $client->getResponse()->getContent()),
        );
    }

    private function getAdminUser(): User
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
