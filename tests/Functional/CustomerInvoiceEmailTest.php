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
 * The separate invoice email of a contact: entered in the customer form, stored with the
 * address and shown next to the regular email.
 */
final class CustomerInvoiceEmailTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testNewCustomerFormStoresTheInvoiceEmail(): void
    {
        $client = $this->loggedInClient();
        $lastname = 'Invoicemail'.bin2hex(random_bytes(4));

        $crawler = $client->request('GET', '/customers/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#customer-form-new')->form();
        $values = $form->getPhpValues();
        $values['lastname-new'] = $lastname;
        $values['email-new'][0] = 'guest@example.com';
        $values['invoiceemail-new'][0] = 'accounts@example.com';

        $client->request('POST', '/customers/create', $values);
        self::assertResponseIsSuccessful();

        $customer = $this->em()->getRepository(Customer::class)->findOneBy(['lastname' => $lastname]);
        self::assertInstanceOf(Customer::class, $customer);
        $address = $customer->getCustomerAddresses()->first();
        self::assertInstanceOf(CustomerAddresses::class, $address);
        self::assertSame('guest@example.com', $address->getEmail());
        self::assertSame('accounts@example.com', $address->getInvoiceEmail());
    }

    public function testEditFormAndDetailsShowTheStoredInvoiceEmail(): void
    {
        $client = $this->loggedInClient();
        $customer = $this->createCustomer('accounts@example.com');

        $crawler = $client->request('GET', sprintf('/customers/%d/edit/show', $customer->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('accounts@example.com', $crawler->filter('input[name="invoiceemail-'.$customer->getId().'[]"]')->attr('value'));

        $crawler = $client->request('GET', sprintf('/customers/%d/get', $customer->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('accounts@example.com', $crawler->filter('#accordion')->text());
    }

    public function testDetailsOmitTheInvoiceEmailWhenNoneIsSet(): void
    {
        $client = $this->loggedInClient();
        $customer = $this->createCustomer(null);

        $client->request('GET', sprintf('/customers/%d/get', $customer->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('E-Mail für Rechnungen', (string) $client->getResponse()->getContent());
    }

    private function createCustomer(?string $invoiceEmail): Customer
    {
        $em = $this->em();
        $address = (new CustomerAddresses())
            ->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE')
            ->setEmail('guest@example.com')
            ->setInvoiceEmail($invoiceEmail);
        $customer = new Customer();
        $customer->setSalutation('Herr');
        $customer->setFirstname('Jan');
        $customer->setLastname('Invoicemail'.bin2hex(random_bytes(4)));
        $customer->addCustomerAddress($address);
        $em->persist($address);
        $em->persist($customer);
        $em->flush();

        return $customer;
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $this->em();

        $user = new User();
        $user->setUsername('invmail_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('Customers');
        $user->setEmail(sprintf('invmail+%s@example.com', bin2hex(random_bytes(4))));
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
