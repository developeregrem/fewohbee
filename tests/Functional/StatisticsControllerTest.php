<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Invoice;
use App\Entity\InvoiceAppartment;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\Role;
use App\Entity\Subsidiary;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class StatisticsControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
    }

    public function testUtilizationMonthlyUsesLiveData(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_STATISTICS']);
        $client->loginUser($user);

        $this->createStatisticsScenario('2099-03-01', '2099-03-03');

        $client->request('GET', '/statistics/utilization/monthtly', [
            'objectId' => 'all',
            'monthStart' => 3,
            'monthEnd' => 3,
            'yearStart' => 2099,
            'yearEnd' => 2099,
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame([1, 2, 3], array_slice($payload['labels'], 0, 3));
        self::assertNotEmpty($payload['datasets']);
        $data = $payload['datasets'][0]['data'];
        self::assertGreaterThan(0, $data[0], 'Expected utilization for day 1.');
        self::assertGreaterThan(0, $data[1], 'Expected utilization for day 2.');
        self::assertSame(0.0, (float) $data[2], 'Expected no utilization for day 3.');
    }

    public function testOriginMonthlyUsesLiveData(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_STATISTICS']);
        $client->loginUser($user);

        $this->createStatisticsScenario('2099-04-01', '2099-04-03');

        $client->request('GET', '/statistics/origin/monthtly', [
            'objectId' => 'all',
            'monthStart' => 4,
            'monthEnd' => 4,
            'yearStart' => 2099,
            'yearEnd' => 2099,
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(['Web'], $payload['labels']);
        self::assertSame([1], $payload['datasets'][0]['data']);
    }

    public function testTurnoverMonthlyUsesLiveData(): void
    {
        $client = static::createClient();
        $user = $this->createUserWithRoles(['ROLE_STATISTICS']);
        $client->loginUser($user);

        $this->createStatisticsScenario('2099-05-01', '2099-05-03');

        $client->request('GET', '/statistics/turnover/monthly', [
            'yearStart' => 2099,
            'yearEnd' => 2099,
            'invoice-status' => [2],
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertNotEmpty($payload['datasets']);
        $data = $payload['datasets'][0]['data'];
        self::assertSame(200.0, (float) $data[4]);
    }

    private function createStatisticsScenario(string $start, string $end): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $subsidiary = new Subsidiary();
        $subsidiary->setName('Test Subsidiary');
        $subsidiary->setDescription('Test');
        $em->persist($subsidiary);

        $appartment = new Appartment();
        $appartment->setNumber('A-1');
        $appartment->setBedsMax(2);
        $appartment->setDescription('Test room');
        $appartment->setObject($subsidiary);
        $em->persist($appartment);

        $origin = new ReservationOrigin();
        $origin->setName('Web');
        $em->persist($origin);

        $status = new ReservationStatus();
        $status->setName('Confirmed');
        $status->setColor('#00aa00');
        $status->setContrastColor('#ffffff');
        $em->persist($status);

        $customer = new Customer();
        $customer->setSalutation('Mr');
        $customer->setFirstname('Test');
        $customer->setLastname('Customer');
        $em->persist($customer);

        $address = new CustomerAddresses();
        $address->setType('CUSTOMER_ADDRESS_TYPE_PRIVATE');
        $address->setCountry('DE');
        $em->persist($address);
        $customer->addCustomerAddress($address);

        $reservation = new Reservation();
        $reservation->setReservationOrigin($origin);
        $reservation->setReservationStatus($status);
        $reservation->setBooker($customer);
        $reservation->addCustomer($customer);
        $reservation->setPersons(2);
        $reservation->setStartDate(new \DateTime($start));
        $reservation->setEndDate(new \DateTime($end));
        $reservation->setAppartment($appartment);
        $reservation->setReservationDate(new \DateTime('2099-02-20'));
        $reservation->setIsConflict(false);
        $reservation->setIsConflictIgnored(false);
        $reservation->setUuid(Uuid::v4());
        $em->persist($reservation);

        $invoice = new Invoice();
        $invoice->setNumber('INV-'.substr($start, 0, 7));
        $invoiceDate = new \DateTime(substr($start, 0, 8).'15');
        $invoice->setDate($invoiceDate);
        $invoice->setStatus(2);
        $em->persist($invoice);

        $invoiceAppartment = new InvoiceAppartment();
        $invoiceAppartment->setNumber('A-1');
        $invoiceAppartment->setDescription('Test room');
        $invoiceAppartment->setBeds(2);
        $invoiceAppartment->setPersons(2);
        $invoiceAppartment->setStartDate(new \DateTime($start));
        $invoiceAppartment->setEndDate(new \DateTime($end));
        $invoiceAppartment->setPrice(100);
        $invoiceAppartment->setVat(0);
        $invoiceAppartment->setIncludesVat(true);
        $invoiceAppartment->setIsFlatPrice(false);
        $invoiceAppartment->setInvoice($invoice);
        $invoice->addAppartment($invoiceAppartment);
        $em->persist($invoiceAppartment);

        $em->flush();
    }

    /**
     * @param string[] $roleCodes
     */
    private function createUserWithRoles(array $roleCodes)
    {
        $container = static::getContainer();
        $doctrine = $container->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new \App\Entity\User();
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
}
