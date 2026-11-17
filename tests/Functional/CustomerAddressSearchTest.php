<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The address search of the customer form (<twig:Autocomplete>): suggestions name the guest an
 * address belongs to, and the form renders both lookups as autocomplete fields.
 */
final class CustomerAddressSearchTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testSuggestionsCarryTheGuestsName(): void
    {
        $client = $this->loggedInClient();
        $lastname = 'Addrsearch'.bin2hex(random_bytes(4));
        $this->createCustomer($lastname);

        $client->request('GET', '/customers/search/address/'.$lastname);
        self::assertResponseIsSuccessful();

        $suggestions = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($suggestions);
        self::assertCount(1, $suggestions);
        self::assertSame($lastname.', Jana', $suggestions[0]['customer']);
        self::assertSame('Hauptstraße 1', $suggestions[0]['address']);
        self::assertSame('12345', $suggestions[0]['zip']);
        self::assertSame('Musterstadt', $suggestions[0]['city']);
    }

    public function testCustomerFormRendersAddressSearchAndPostalCodeLookup(): void
    {
        $client = $this->loggedInClient();

        $crawler = $client->request('GET', '/customers/new');
        self::assertResponseIsSuccessful();

        $search = $crawler->filter('#customer-address');
        self::assertSame('autocomplete', $search->attr('data-controller'));
        self::assertSame('/customers/search/address/__query__', $search->attr('data-autocomplete-url-value'));

        $zip = $crawler->filter('input[name="zip-new[]"]')->first();
        self::assertSame('autocomplete', $zip->attr('data-controller'));
        self::assertSame('/customers/citylookup/__country__/__query__', $zip->attr('data-autocomplete-url-value'));
    }

    private function createCustomer(string $lastname): void
    {
        $em = $this->em();
        $address = (new CustomerAddresses())
            ->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE')
            ->setAddress('Hauptstraße 1')
            ->setZip('12345')
            ->setCity('Musterstadt');
        $customer = new Customer();
        $customer->setSalutation('Frau');
        $customer->setFirstname('Jana');
        $customer->setLastname($lastname);
        $customer->addCustomerAddress($address);
        $em->persist($address);
        $em->persist($customer);
        $em->flush();
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $this->em();

        $user = new User();
        $user->setUsername('addrsearch_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Customers');
        $user->setEmail(sprintf('addrsearch+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setLastSeenVersion('99.99.99');
        $role = $em->getRepository(Role::class)->findOneBy(['role' => 'ROLE_CUSTOMERS']);
        $user->setRoleEntities(null !== $role ? [$role] : []);
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
