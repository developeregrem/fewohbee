<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The day count of the reservation table is clamped server-side. The lower
 * bound is one week: the server counts days inclusively, so an interval of 6
 * shows Monday to Sunday.
 */
final class ReservationTableIntervalTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function intervals(): iterable
    {
        yield 'one week is allowed' => ['6', 7];
        yield 'below one week is raised to one week' => ['3', 7];
        yield 'above one week is kept' => ['7', 8];
    }

    #[DataProvider('intervals')]
    public function testDayCountIsClampedToAtLeastOneWeek(string $interval, int $expectedDays): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS']));

        $crawler = $client->request('GET', '/reservation/table', [
            'start' => '2026-10-05',
            'interval' => $interval,
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount($expectedDays, $crawler->filter('#reservation-table thead tr.table-days th[data-day]'));
    }

    /**
     * @param list<string> $roleCodes
     */
    private function createUserWithRoles(array $roleCodes): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            self::assertNotNull($role, sprintf('Role %s must exist in database.', $roleCode));
            $roles[] = $role;
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
