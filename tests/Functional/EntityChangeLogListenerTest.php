<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Customer;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Behavioural tests for the audit-log listener. These exercise the real
 * Doctrine flush cycle (no mocks) so they catch listener-wiring regressions,
 * not just internal logic.
 */
final class EntityChangeLogListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        /** @var ManagerRegistry $registry */
        $registry = static::getContainer()->get(ManagerRegistry::class);
        $em = $registry->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->conn = $em->getConnection();
        $this->conn->executeStatement('DELETE FROM logging');
    }

    public function testCreateLogsAnInsertRowWithSnapshotChangeset(): void
    {
        $customer = $this->newCustomer('Audit', 'Create');
        $this->em->persist($customer);
        $this->em->flush();

        $rows = $this->fetchLog();
        self::assertCount(1, $rows);
        self::assertSame('create', $rows[0]['action']);
        self::assertSame(Customer::class, $rows[0]['entity_class']);
        self::assertSame((string) $customer->getId(), $rows[0]['entity_id']);

        $changes = $this->decode($rows[0]['changes']);
        self::assertSame([null, 'Audit'], $changes['firstname']);
        self::assertSame([null, 'Create'], $changes['lastname']);
    }

    public function testUpdateLogsOnlyChangedFields(): void
    {
        $customer = $this->newCustomer('Before', 'Same');
        $this->em->persist($customer);
        $this->em->flush();
        $this->conn->executeStatement('DELETE FROM logging');

        $customer->setFirstname('After');
        $this->em->flush();

        $rows = $this->fetchLog();
        self::assertCount(1, $rows);
        self::assertSame('update', $rows[0]['action']);

        $changes = $this->decode($rows[0]['changes']);
        self::assertSame(['firstname' => ['Before', 'After']], $changes,
            'Update diff must contain only the modified field — no untouched fields.');
    }

    public function testDeleteLogsPreDeletionSnapshot(): void
    {
        $customer = $this->newCustomer('ToDelete', 'Smoke');
        $this->em->persist($customer);
        $this->em->flush();
        $customerId = (string) $customer->getId();
        $this->conn->executeStatement('DELETE FROM logging');

        $this->em->remove($customer);
        $this->em->flush();

        $rows = $this->fetchLog();
        self::assertCount(1, $rows);
        self::assertSame('delete', $rows[0]['action']);
        self::assertSame($customerId, $rows[0]['entity_id'],
            'Delete row must capture the entity id BEFORE the row is gone.');

        $changes = $this->decode($rows[0]['changes']);
        self::assertSame(['ToDelete', null], $changes['firstname']);
        self::assertSame(['Smoke', null], $changes['lastname']);
    }

    public function testSensitiveFieldsAreRedacted(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user, 'Test user "test-admin" must exist (run bin/run-tests.sh).');

        $originalPassword = $user->getPassword();
        $newPassword = 'tmp-hash-'.bin2hex(random_bytes(8));
        $user->setPassword($newPassword);
        $this->em->flush();

        $rows = $this->fetchLog();
        self::assertCount(1, $rows);
        $changes = $this->decode($rows[0]['changes']);
        self::assertArrayHasKey('password', $changes, 'A password change still produces a diff entry, just redacted.');
        self::assertSame(['***redacted***', '***redacted***'], $changes['password']);
        self::assertStringNotContainsString($newPassword, $rows[0]['changes'],
            'The literal password value must never appear in the serialized log row.');

        // restore so subsequent fast-loop runs (make phpunit) still see a real diff
        $user->setPassword($originalPassword);
        $this->em->flush();
    }

    public function testLastActionUpdatesAreFilteredOutOfTheDiff(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);

        $originalFirstname = $user->getFirstname();
        $user->setLastAction(new \DateTime('2026-05-13 09:00:00'));
        $user->setFirstname('FilteredUpdate');
        $this->em->flush();

        $rows = $this->fetchLog();
        self::assertCount(1, $rows);
        $changes = $this->decode($rows[0]['changes']);
        self::assertArrayHasKey('firstname', $changes);
        self::assertArrayNotHasKey('lastAction', $changes,
            'lastAction is touched on every request by LastActionSubscriber; the listener must scrub it from update diffs.');

        // restore original to keep test fixtures stable for subsequent tests
        $user->setFirstname($originalFirstname);
        $this->em->flush();
    }

    public function testLastActionOnlyUpdateProducesNoLogRow(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'test-admin']);
        self::assertInstanceOf(User::class, $user);

        $user->setLastAction(new \DateTime('2026-05-13 09:05:00'));
        $this->em->flush();

        self::assertCount(0, $this->fetchLog(),
            'An update whose only delta is a filtered field must not produce a log row at all.');
    }

    private function newCustomer(string $first, string $last): Customer
    {
        $customer = new Customer();
        $customer->setSalutation('Mr');
        $customer->setFirstname($first);
        $customer->setLastname($last);

        return $customer;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLog(): array
    {
        return $this->conn
            ->executeQuery('SELECT action, entity_class, entity_id, changes FROM logging ORDER BY id')
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        self::assertIsString($json);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
