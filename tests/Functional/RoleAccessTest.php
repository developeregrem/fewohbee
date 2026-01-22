<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;

final class RoleAccessTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testDashboardRedirectsToFirstAccessibleRoute(): void
    {
        foreach ($this->dashboardCases() as $case => [$roles, $expectedRoute]) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $user = $this->createUserWithRoles($roles);
            $client->loginUser($user);

            $client->request('GET', '/dashboard');

            $response = $client->getResponse();
            self::assertTrue($response->isRedirect(), sprintf('[%s] Expected redirect.', $case));
            self::assertSame(302, $response->getStatusCode(), sprintf('[%s] Expected 302 redirect.', $case));

            $expectedPath = $this->generatePath($expectedRoute);
            $location = $response->headers->get('Location') ?? '';
            self::assertStringEndsWith($expectedPath, $location, sprintf('[%s] Redirect target mismatch.', $case));
        }
    }

    public function testAuthorizedFeatureRoutesAreReachable(): void
    {
        foreach ($this->authorizedRoutes() as $case => [$role, $path]) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $user = $this->createUserWithRoles([$role]);
            $client->loginUser($user);

            $client->request('GET', $path);

            $status = $client->getResponse()->getStatusCode();
            self::assertLessThan(400, $status, sprintf('[%s] Expected access, got %d.', $case, $status));
        }
    }

    public function testUnauthorizedRoutesAreForbidden(): void
    {
        foreach ($this->unauthorizedRoutes() as $case => [$role, $path]) {
            self::ensureKernelShutdown();
            $client = static::createClient();
            $user = $this->createUserWithRoles([$role]);
            $client->loginUser($user);

            $client->request('GET', $path);

            self::assertResponseStatusCodeSame(403, sprintf('[%s] Expected 403 for forbidden route.', $case));
        }
    }

    /**
     * @param string[] $roleCodes
     */
    private function createUserWithRoles(array $roleCodes): User
    {
        $container = static::getContainer();
        $doctrine = $container->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
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

        return $user;
    }

    private function generatePath(string $routeName): string
    {
        /** @var RouterInterface $router */
        $router = static::getContainer()->get(RouterInterface::class);

        return $router->generate($routeName);
    }

    /**
     * @return iterable<string, array{roles: string[], expectedRoute: string}>
     */
    private function dashboardCases(): iterable
    {
        yield 'reservations' => [['ROLE_RESERVATIONS'], 'start'];
        yield 'housekeeping' => [['ROLE_HOUSEKEEPING'], 'operations.housekeeping'];
        yield 'customers' => [['ROLE_CUSTOMERS'], 'customers.overview'];
        yield 'invoices' => [['ROLE_INVOICES'], 'invoices.overview'];
        yield 'registrationbook' => [['ROLE_REGISTRATIONBOOK'], 'registrationbook.overview'];
        yield 'statistics' => [['ROLE_STATISTICS'], 'statistics.utilization'];
        yield 'cashjournal' => [['ROLE_CASHJOURNAL'], 'cashjournal.overview'];
        yield 'reservations ro' => [['ROLE_RESERVATIONS_RO'], 'start'];
        yield 'admin' => [['ROLE_ADMIN'], 'start'];
        yield 'multiple roles order' => [['ROLE_INVOICES', 'ROLE_CUSTOMERS'], 'customers.overview'];
    }

    /**
     * @return iterable<string, array{role: string, path: string}>
     */
    private function authorizedRoutes(): iterable
    {
        yield 'reservations read only' => ['ROLE_RESERVATIONS_RO', '/reservation/'];
        yield 'housekeeping' => ['ROLE_HOUSEKEEPING', '/operations/housekeeping'];
        yield 'customers' => ['ROLE_CUSTOMERS', '/customers/'];
        yield 'invoices' => ['ROLE_INVOICES', '/invoices/'];
        yield 'registrationbook' => ['ROLE_REGISTRATIONBOOK', '/registrationbook/'];
        yield 'statistics' => ['ROLE_STATISTICS', '/statistics/utilization'];
        yield 'cashjournal' => ['ROLE_CASHJOURNAL', '/cashjournal/'];
        yield 'admin' => ['ROLE_ADMIN', '/settings/users'];
    }

    /**
     * @return iterable<string, array{role: string, path: string}>
     */
    private function unauthorizedRoutes(): iterable
    {
        yield 'invoices user on reservations' => ['ROLE_INVOICES', '/reservation/'];
        yield 'reservations ro user on invoices' => ['ROLE_RESERVATIONS_RO', '/invoices/'];
        yield 'customers user on cashjournal' => ['ROLE_CUSTOMERS', '/cashjournal/'];
        yield 'cashjournal user on customers' => ['ROLE_CASHJOURNAL', '/customers/'];
    }
}
