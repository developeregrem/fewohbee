<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CalendarSync;
use App\Entity\Enum\ApiScope;
use App\Entity\Role;
use App\Entity\User;
use App\Service\ApiTokenService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ApiCalendarControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testIcsViaBasicAuthWithToken(): void
    {
        $client = static::createClient();
        [$user, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::CALENDAR_READ->value]);
        $apartmentId = $this->getSyncedApartmentId();

        $client->request('GET', sprintf('/api/v1/apartments/%d/calendar.ics', $apartmentId), [], [], [
            'PHP_AUTH_USER' => $user->getUsername(),
            'PHP_AUTH_PW' => $plainToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('text/calendar; charset=utf-8', $client->getResponse()->headers->get('content-type'));
        self::assertNotSame('', (string) $client->getResponse()->getContent());
    }

    public function testIcsDoesNotRequirePublicFlag(): void
    {
        $client = static::createClient();
        [$user, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::CALENDAR_READ->value]);

        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $sync = $em->getRepository(CalendarSync::class)->findOneBy([]);
        self::assertNotNull($sync, 'Calendar sync must exist in fixtures.');
        $sync->setIsPublic(false);
        $em->flush();

        $client->request('GET', sprintf('/api/v1/apartments/%d/calendar.ics', $sync->getApartment()->getId()), [], [], [
            'PHP_AUTH_USER' => $user->getUsername(),
            'PHP_AUTH_PW' => $plainToken,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testIcsWithoutCalendarScopeReturns403(): void
    {
        $client = static::createClient();
        [$user, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::RESERVATIONS_READ->value]);
        $apartmentId = $this->getSyncedApartmentId();

        $client->request('GET', sprintf('/api/v1/apartments/%d/calendar.ics', $apartmentId), [], [], [
            'PHP_AUTH_USER' => $user->getUsername(),
            'PHP_AUTH_PW' => $plainToken,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testIcsUnknownApartmentReturns404(): void
    {
        $client = static::createClient();
        [$user, $plainToken] = $this->createUserWithToken(['ROLE_RESERVATIONS_RO'], [ApiScope::CALENDAR_READ->value]);

        $client->request('GET', '/api/v1/apartments/999999/calendar.ics', [], [], [
            'PHP_AUTH_USER' => $user->getUsername(),
            'PHP_AUTH_PW' => $plainToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    private function getSyncedApartmentId(): int
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $sync = $em->getRepository(CalendarSync::class)->findOneBy([]);
        self::assertNotNull($sync, 'Calendar sync must exist in fixtures.');

        return (int) $sync->getApartment()->getId();
    }

    /**
     * @param string[]     $roleCodes
     * @param list<string> $scopes
     *
     * @return array{0: User, 1: string}
     */
    private function createUserWithToken(array $roleCodes, array $scopes): array
    {
        $container = static::getContainer();
        $doctrine = $container->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('api_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Api');
        $user->setLastname('Tester');
        $user->setEmail(sprintf('api+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            self::assertNotNull($role, sprintf('Role %s must exist in database.', $roleCode));
            $roles[] = $role;
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        $result = $container->get(ApiTokenService::class)->createToken($user, 'functional-test', $scopes, null);

        return [$user, $result->plainToken];
    }
}
