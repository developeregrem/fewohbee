<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the scheduling window of workflow:process-scheduled against a real database:
 * when a workflow may run, and that a workflow without an entity cannot fire twice on
 * the same day just because the cron passes every 15 minutes.
 *
 * The weekday arithmetic itself is pinned to fixed dates in ScheduleWindowTest; here
 * only the wiring — command, trigger and execution log — is under test.
 */
final class ProcessScheduledWorkflowsCommandTest extends KernelTestCase
{
    private const SYSTEM_CODE_PREFIX = 'test_scheduled_';

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

        $this->removeTestWorkflows();

        $application = new Application($kernel);
        $this->tester = new CommandTester($application->find('workflow:process-scheduled'));
    }

    protected function tearDown(): void
    {
        $this->removeTestWorkflows();
        parent::tearDown();
    }

    public function testMonthlyWorkflowDoesNotFireTwiceOnTheSameDay(): void
    {
        $workflowId = $this->insertMonthlyWorkflow('once_a_day', runAtHour: 0);
        $this->insertSuccessLog($workflowId, new \DateTimeImmutable('today 00:30'));

        $exit = $this->tester->execute([]);

        self::assertSame(0, $exit);
        self::assertSame(1, $this->countLogs($workflowId),
            'A monthly workflow that already ran today must not be executed again by a later cron pass.');
    }

    public function testMonthlyWorkflowFiresAgainAfterAnEarlierMonth(): void
    {
        $workflowId = $this->insertMonthlyWorkflow('next_month', runAtHour: 0);
        $this->insertSuccessLog($workflowId, new \DateTimeImmutable('-35 days'));

        $exit = $this->tester->execute([]);

        self::assertSame(0, $exit);
        self::assertSame(2, $this->countLogs($workflowId),
            'Last month\'s run must not block this month\'s.');
    }

    public function testWorkflowIsNotProcessedBeforeItsConfiguredHour(): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        if ($currentHour >= 23) {
            self::markTestSkipped('No later hour left today to schedule against.');
        }

        $workflowId = $this->insertMonthlyWorkflow('later_today', runAtHour: $currentHour + 1);

        $exit = $this->tester->execute([]);

        self::assertSame(0, $exit);
        self::assertSame(0, $this->countLogs($workflowId),
            'A workflow scheduled for a later hour must be left alone by this cron pass.');
    }

    public function testDryRunReportsWorkflowsOutsideTheirWindow(): void
    {
        $currentHour = (int) (new \DateTimeImmutable())->format('G');
        if ($currentHour >= 23) {
            self::markTestSkipped('No later hour left today to schedule against.');
        }

        $this->insertMonthlyWorkflow('window_note', runAtHour: $currentHour + 1);

        $this->tester->execute(['--dry-run' => true]);

        self::assertStringContainsString('outside its schedule window', $this->tester->getDisplay());
    }

    public function testEntityTriggerWithCatchUpRangeQueriesSuccessfully(): void
    {
        // The catch-up range turned the exact-date match into a BETWEEN; make sure that
        // query is valid DQL against the real schema for both entity triggers.
        $preset = self::presetIncludingToday();

        $this->insertWorkflow('invoice_range', 'invoice.days_after_date', [
            'days' => 14,
            'runOnDays' => $preset,
            'runAtHour' => 0,
        ], 'change_invoice_status', ['status' => 1]);
        $this->insertWorkflow('reservation_range', 'reservation.days_before_start', [
            'days' => 3,
            'runOnDays' => $preset,
            'runAtHour' => 0,
        ], 'create_in_app_notification', ['severity' => 'info']);

        $exit = $this->tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit);
    }

    /**
     * A preset that lets the workflow run today, so the query is always exercised.
     * From Monday to Friday this is mon_fri, which on a Monday also covers the
     * widened weekend range.
     */
    private static function presetIncludingToday(): string
    {
        return match ((int) (new \DateTimeImmutable())->format('N')) {
            6 => 'mon_sat',
            7 => 'daily',
            default => 'mon_fri',
        };
    }

    private function insertMonthlyWorkflow(string $code, int $runAtHour): int
    {
        return $this->insertWorkflow(
            $code,
            'schedule.monthly',
            [
                'dayOfMonth' => (int) (new \DateTimeImmutable())->format('j'),
                'runOnDays' => 'daily',
                'runAtHour' => $runAtHour,
            ],
            // Any action does: the assertions only look at whether the workflow was
            // picked up at all, which shows up as a log row either way.
            'create_in_app_notification',
            ['severity' => 'info'],
        );
    }

    /**
     * @param array<string, mixed> $triggerConfig
     * @param array<string, mixed> $actionConfig
     */
    private function insertWorkflow(
        string $code,
        string $triggerType,
        array $triggerConfig,
        string $actionType,
        array $actionConfig,
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO workflows (name, description, is_enabled, is_system, system_code, trigger_type, trigger_config, conditions, action_type, action_config, priority, created_at, updated_at)
             VALUES (:name, NULL, 1, 0, :code, :triggerType, :triggerConfig, :conditions, :actionType, :actionConfig, 0, :now, :now)',
            [
                'name' => 'Test ' . $code,
                'code' => self::SYSTEM_CODE_PREFIX . $code,
                'triggerType' => $triggerType,
                'triggerConfig' => json_encode($triggerConfig, JSON_THROW_ON_ERROR),
                'conditions' => '[]',
                'actionType' => $actionType,
                'actionConfig' => json_encode($actionConfig, JSON_THROW_ON_ERROR),
                'now' => $now,
            ]
        );

        return (int) $this->conn->lastInsertId();
    }

    private function insertSuccessLog(int $workflowId, \DateTimeImmutable $executedAt): void
    {
        $this->conn->executeStatement(
            'INSERT INTO workflow_logs (workflow_id, workflow_name, trigger_type, entity_class, entity_id, status, message, executed_at)
             VALUES (:workflowId, :name, :triggerType, NULL, NULL, :status, :message, :executedAt)',
            [
                'workflowId' => $workflowId,
                'name' => 'Test workflow',
                'triggerType' => 'schedule.monthly',
                'status' => 'success',
                'message' => 'seeded by test',
                'executedAt' => $executedAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    private function countLogs(int $workflowId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM workflow_logs WHERE workflow_id = :id',
            ['id' => $workflowId]
        );
    }

    private function removeTestWorkflows(): void
    {
        $this->conn->executeStatement(
            'DELETE FROM workflow_logs WHERE workflow_id IN (SELECT id FROM workflows WHERE system_code LIKE :prefix)',
            ['prefix' => self::SYSTEM_CODE_PREFIX . '%']
        );
        $this->conn->executeStatement(
            'DELETE FROM workflows WHERE system_code LIKE :prefix',
            ['prefix' => self::SYSTEM_CODE_PREFIX . '%']
        );
    }
}
