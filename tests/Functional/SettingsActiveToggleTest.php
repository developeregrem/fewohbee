<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Enum\ModifierType;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Entity\Role;
use App\Entity\TouristTax;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The switches in the settings lists (ActiveToggle): rooms, guest categories and their price
 * modifiers, tourist taxes and users go off and on from their list without reloading it.
 */
final class SettingsActiveToggleTest extends WebTestCase
{
    private const PREFIX = 'toggle-test-';

    private ?int $touchedRoomId = null;

    public function testARoomSwitchesOffAndOnFromTheList(): void
    {
        $client = $this->adminClient();
        $room = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true]);
        self::assertInstanceOf(Appartment::class, $room);
        $id = (int) $room->getId();
        $this->touchedRoomId = $id;

        $this->assertSwitchesTwice($client, '/settings/apartments/', sprintf('/settings/apartments/%d/toggle', $id),
            fn (): bool => $this->reload(Appartment::class, $id)->isActive(), true);
    }

    public function testAGuestCategoryAndItsModifierSwitchFromTheList(): void
    {
        $client = $this->adminClient();
        $category = (new GuestCategory())->setName(self::PREFIX.'category')->setAcronym('TT');
        $modifier = (new GuestCategoryModifier())->setCategory($category)->setType(ModifierType::FREE)->setActive(false);
        $this->em()->persist($category);
        $this->em()->persist($modifier);
        $this->em()->flush();
        $categoryId = (int) $category->getId();
        $modifierId = (int) $modifier->getId();

        $this->assertSwitchesTwice($client, '/settings/guest-category/', sprintf('/settings/guest-category/%d/toggle', $categoryId),
            fn (): bool => $this->reload(GuestCategory::class, $categoryId)->isActive(), true);
        $this->assertSwitchesTwice($client, '/settings/guest-category/', sprintf('/settings/guest-category/modifiers/%d/toggle', $modifierId),
            fn (): bool => $this->reload(GuestCategoryModifier::class, $modifierId)->isActive(), false);
    }

    public function testATouristTaxSwitchesFromTheList(): void
    {
        $client = $this->adminClient();
        $tax = (new TouristTax())->setName(self::PREFIX.'tax')->setActive(false);
        $this->em()->persist($tax);
        $this->em()->flush();
        $id = (int) $tax->getId();

        $this->assertSwitchesTwice($client, '/settings/tourist-tax/', sprintf('/settings/tourist-tax/%d/toggle', $id),
            fn (): bool => $this->reload(TouristTax::class, $id)->isActive(), false);
    }

    public function testAnotherUserSwitchesFromTheList(): void
    {
        $client = $this->adminClient();
        $id = (int) $this->staffUser()->getId();

        $this->assertSwitchesTwice($client, '/settings/users/', sprintf('/settings/users/%d/toggle', $id),
            fn (): bool => (bool) $this->reload(User::class, $id)->getActive(), true);
    }

    /** Switched off, one's own account could not log in again to switch itself back on. */
    public function testOnesOwnAccountStaysOn(): void
    {
        $client = $this->adminClient();
        $adminId = (int) $this->admin()->getId();

        $crawler = $client->request('GET', '/settings/users/');
        self::assertCount(0, $crawler->filter(sprintf('form[action="/settings/users/%d/toggle"]', $adminId)));

        $this->postToggle($client, sprintf('/settings/users/%d/toggle', $adminId), '');

        self::assertResponseStatusCodeSame(409);
        self::assertTrue($this->reload(User::class, $adminId)->getActive());
    }

    public function testWithoutAValidTokenNothingChanges(): void
    {
        $client = $this->adminClient();
        $room = $this->em()->getRepository(Appartment::class)->findOneBy(['active' => true]);
        self::assertInstanceOf(Appartment::class, $room);
        $id = (int) $room->getId();
        $this->touchedRoomId = $id;

        $this->postToggle($client, sprintf('/settings/apartments/%d/toggle', $id), 'invalid');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reload(Appartment::class, $id)->isActive());
    }

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $em = $this->em();
            if ($em->isOpen()) {
                $em->clear();
                if (null !== $this->touchedRoomId) {
                    $em->find(Appartment::class, $this->touchedRoomId)?->setActive(true);
                }
                foreach ($em->getRepository(GuestCategory::class)->findBy(['name' => self::PREFIX.'category']) as $category) {
                    foreach ($em->getRepository(GuestCategoryModifier::class)->findBy(['category' => $category]) as $modifier) {
                        $em->remove($modifier);
                    }
                    $em->remove($category);
                }
                foreach ($em->getRepository(TouristTax::class)->findBy(['name' => self::PREFIX.'tax']) as $tax) {
                    $em->remove($tax);
                }
                foreach ($em->getRepository(User::class)->findBy(['username' => self::PREFIX.'staff']) as $user) {
                    $em->remove($user);
                }
                $em->flush();
            }
        }
        parent::tearDown();
    }

    /**
     * Switches a record with the switch its list renders, then back again.
     *
     * @param callable(): bool $isActive reads the record's state afresh
     */
    private function assertSwitchesTwice(KernelBrowser $client, string $listPath, string $togglePath, callable $isActive, bool $initial): void
    {
        self::assertSame($initial, $isActive());

        $this->postToggle($client, $togglePath, $this->token($client, $listPath, $togglePath));
        self::assertResponseStatusCodeSame(204);
        self::assertSame(!$initial, $isActive());

        $this->postToggle($client, $togglePath, $this->token($client, $listPath, $togglePath));
        self::assertResponseStatusCodeSame(204);
        self::assertSame($initial, $isActive());
    }

    private function token(KernelBrowser $client, string $listPath, string $togglePath): string
    {
        $crawler = $client->request('GET', $listPath);
        self::assertResponseIsSuccessful();
        $field = $crawler->filter(sprintf('form[action="%s"] input[name="_token"]', $togglePath));
        self::assertCount(1, $field, 'The list renders the switch.');

        return (string) $field->attr('value');
    }

    private function postToggle(KernelBrowser $client, string $togglePath, string $token): void
    {
        $this->em()->clear();
        $client->request('POST', $togglePath, ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function reload(string $class, int $id): object
    {
        $this->em()->clear();

        return $this->em()->find($class, $id) ?? throw new \RuntimeException($class.' '.$id.' missing.');
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser($this->admin(), 'main');

        return $client;
    }

    private function admin(): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['username' => 'test-admin']) ?? throw new \RuntimeException('Prepared test admin missing.');
    }

    private function staffUser(): User
    {
        $em = $this->em();
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_RESERVATIONS'])
            ?? throw new \RuntimeException('Role ROLE_RESERVATIONS missing.');
        $user = new User();
        $user->setUsername(self::PREFIX.'staff');
        $user->setFirstname('Toggle');
        $user->setLastname('Staff');
        $user->setEmail('toggle-test-staff@example.com');
        $user->setPassword('unused');
        $user->setActive(true);
        $user->setRoleEntities([$role]);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
