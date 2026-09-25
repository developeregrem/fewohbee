<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeLogsCommandTest extends KernelTestCase
{
    private Connection $conn;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $kernel = self::bootKernel();

        /** @var ManagerRegistry $registry */
        $registry = static::getContainer()->get(ManagerRegistry::class);
        $em = $registry->getManager();
        assert($em instanceof EntityManagerInterface);
        $this->conn = $em->getConnection();

        $this->conn->executeStatement('DELETE FROM logging');
        $this->conn->executeStatement('DELETE FROM workflow_logs');

        $application = new Application($kernel);
        $this->tester = new CommandTester($application->find('app:purge-logs'));
    }

    public function testPurgesOldRowsAndPiiAcrossBothLogTables(): void
    {
        $oldDate = (new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s');
        $recentDate = (new \DateTimeImmutable('-5 days'))->format('Y-m-d H:i:s');

        $this->insertAuditLog($oldDate, ipAddress: '203.0.113.42', username: 'alice');
        $this->insertAuditLog($recentDate, ipAddress: '203.0.113.99', username: 'bob');
        $this->insertWorkflowLog($oldDate);
        $this->insertWorkflowLog($recentDate);

        $exit = $this->tester->execute(['--days' => 90]);

        self::assertSame(0, $exit);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM logging'));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM workflow_logs'));
        self::assertStringContainsString('Deleted 1 audit log, 1 workflow log', $this->tester->getDisplay());

        // GDPR-relevant: the IP/username of the purged row must be gone, only the recent row's PII remains.
        $remainingIps = $this->conn->fetchFirstColumn('SELECT ip_address FROM logging');
        self::assertSame(['203.0.113.99'], $remainingIps,
            'The IP address from the purged row must no longer exist anywhere in the logging table.');
    }

    public function testDropsOnlineCheckInDataLongAfterDeparture(): void
    {
        $rows = $this->conn->fetchAllAssociative('SELECT id, start_date, end_date FROM reservations WHERE id NOT IN (SELECT reservation_id FROM guest_check_in) ORDER BY id LIMIT 2');
        self::assertCount(2, $rows, 'The sample data contains reservations.');
        [$old, $recent] = [(int) $rows[0]['id'], (int) $rows[1]['id']];
        $dates = ['old' => ['-45 days', '-40 days'], 'recent' => ['-15 days', '-10 days']];

        foreach (['old' => $old, 'recent' => $recent] as $name => $reservationId) {
            $this->conn->update('reservations', [
                'start_date' => (new \DateTimeImmutable($dates[$name][0]))->format('Y-m-d'),
                'end_date' => (new \DateTimeImmutable($dates[$name][1]))->format('Y-m-d'),
            ], ['id' => $reservationId]);
            $this->conn->insert('guest_check_in', [
                'reservation_id' => $reservationId,
                'selector' => str_pad($name, 22, 'x'),
                'status' => 'submitted',
                'payload' => '{"v":1,"mainGuest":{"idNumber":"P1"}}',
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        }

        try {
            $this->tester->execute(['--days' => 90]);

            self::assertNull($this->conn->fetchOne('SELECT payload FROM guest_check_in WHERE reservation_id = ?', [$old]));
            self::assertSame('submitted', $this->conn->fetchOne('SELECT status FROM guest_check_in WHERE reservation_id = ?', [$old]));
            self::assertNotNull($this->conn->fetchOne('SELECT payload FROM guest_check_in WHERE reservation_id = ?', [$recent]));
            self::assertStringContainsString('guest data of 1 online check-ins', $this->tester->getDisplay());
        } finally {
            $this->conn->executeStatement('DELETE FROM guest_check_in WHERE reservation_id IN (?, ?)', [$old, $recent]);
            foreach ($rows as $row) {
                $this->conn->update('reservations', ['start_date' => $row['start_date'], 'end_date' => $row['end_date']], ['id' => $row['id']]);
            }
        }
    }

    public function testLegacyAliasEmitsDeprecationWarning(): void
    {
        $exit = $this->tester->execute(['command' => 'workflow:purge-logs', '--days' => 90]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('"workflow:purge-logs" command is deprecated', $this->tester->getDisplay(),
            'Invoking the legacy alias must print a deprecation notice so admins know to migrate their crontab.');
    }

    public function testRejectsNonPositiveDays(): void
    {
        $exit = $this->tester->execute(['--days' => 0]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--days must be a positive integer', $this->tester->getDisplay());
    }

    public function testRejectsNonNumericDays(): void
    {
        $exit = $this->tester->execute(['--days' => 'forever']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('--days must be a positive integer', $this->tester->getDisplay());
    }

    private function insertAuditLog(string $date, ?string $ipAddress = null, ?string $username = null): void
    {
        $this->conn->insert('logging', [
            'date' => $date,
            'entity_class' => 'App\\Entity\\Customer',
            'entity_id' => '1',
            'action' => 'create',
            'changes' => null,
            'ip_address' => $ipAddress,
            'username' => $username,
        ]);
    }

    private function insertWorkflowLog(string $executedAt): void
    {
        $this->conn->insert('workflow_logs', [
            'workflow_name' => 'test-workflow',
            'trigger_type' => 'manual',
            'status' => 'success',
            'executed_at' => $executedAt,
        ]);
    }
}
