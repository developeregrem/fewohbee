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
        self::assertStringContainsString('Deleted 1 audit log and 1 workflow log', $this->tester->getDisplay());

        // GDPR-relevant: the IP/username of the purged row must be gone, only the recent row's PII remains.
        $remainingIps = $this->conn->fetchFirstColumn('SELECT ip_address FROM logging');
        self::assertSame(['203.0.113.99'], $remainingIps,
            'The IP address from the purged row must no longer exist anywhere in the logging table.');
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
