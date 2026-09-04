<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\Subsidiary;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The branch form is the only place the check-in times can be entered, so a template
 * that stops rendering them would leave the feature unreachable without any test noticing.
 */
final class SubsidiaryCheckInFormTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testTheCreateFormOffersTheCheckInFields(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createAdmin());

        $crawler = $client->request('GET', '/settings/objects/new');

        self::assertResponseIsSuccessful();
        // A new branch has no id yet, so the fields carry the 'new' suffix the service reads.
        self::assertCount(1, $crawler->filter('input[name="check-in-from-new"]'));
        self::assertCount(1, $crawler->filter('input[name="check-in-until-new"]'));
        self::assertCount(1, $crawler->filter('input[name="check-out-until-new"]'));
        self::assertCount(1, $crawler->filter('textarea[name="check-in-note-new"]'));
    }

    public function testTheEditFormShowsTheStoredTimes(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createAdmin());

        $subsidiary = new Subsidiary();
        $subsidiary->setName('Check-in branch '.bin2hex(random_bytes(4)));
        $subsidiary->setDescription('Functional test');
        $subsidiary->setCheckInFrom(new \DateTimeImmutable('17:00'));
        $subsidiary->setCheckOutUntil(new \DateTimeImmutable('10:00'));

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->persist($subsidiary);
        $em->flush();

        $crawler = $client->request('GET', '/settings/objects/'.$subsidiary->getId().'/get');

        self::assertResponseIsSuccessful();
        $id = $subsidiary->getId();
        self::assertSame('17:00', $crawler->filter('input[name="check-in-from-'.$id.'"]')->attr('value'));
        self::assertSame('10:00', $crawler->filter('input[name="check-out-until-'.$id.'"]')->attr('value'));
        // No upper bound was set, so the field must stay empty rather than invent one.
        self::assertSame('', $crawler->filter('input[name="check-in-until-'.$id.'"]')->attr('value'));
    }

    private function createAdmin(): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $user = new User();
        $user->setUsername('sub_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Admin');
        $user->setEmail(sprintf('sub+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setLastSeenVersion('99.99.99');

        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_ADMIN']);
        $user->setRoleEntities(null !== $role ? [$role] : []);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
