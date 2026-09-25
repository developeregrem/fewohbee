<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\GuestCheckIn;
use App\Repository\GuestCheckInRepository;
use App\Repository\LogRepository;
use App\Repository\McpToolCallLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\WorkflowLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-logs',
    description: 'Delete audit log, workflow log and notification entries older than a given number of days, and expired online check-in data (run via daily cron).',
    aliases: ['workflow:purge-logs'],
)]
class PurgeLogsCommand extends Command
{
    public function __construct(
        private readonly LogRepository $logRepository,
        private readonly WorkflowLogRepository $workflowLogRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly McpToolCallLogRepository $mcpToolCallLogRepository,
        private readonly GuestCheckInRepository $guestCheckInRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete audit log, workflow log, notification and AI assistant (MCP) call entries older than this many days.', 90);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('workflow:purge-logs' === $input->getFirstArgument()) {
            $io->warning('The "workflow:purge-logs" command is deprecated and will be removed in a future release. Use "app:purge-logs" instead.');
        }

        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT);

        if (false === $days || $days <= 0) {
            $io->error('--days must be a positive integer.');

            return Command::FAILURE;
        }

        $before = new \DateTimeImmutable('-'.$days.' days');
        $audit = $this->logRepository->purgeOlderThan($before);
        $workflow = $this->workflowLogRepository->purgeOlderThan($before);
        // Read state is removed with the notification by the FK cascade.
        $notifications = $this->notificationRepository->purgeOlderThan($before);
        $mcpCalls = $this->mcpToolCallLogRepository->purgeOlderThan($before);
        // Guest data has its own fixed retention, independent of --days: it is personal data
        // including ID numbers, and this is the daily job every installation already runs.
        $checkIns = $this->guestCheckInRepository->purgePayloadsDepartedBefore(
            new \DateTimeImmutable('today -'.GuestCheckIn::RETENTION_DAYS_AFTER_DEPARTURE.' days')
        );

        $io->success(sprintf(
            'Deleted %d audit log, %d workflow log, %d notification and %d AI assistant call entries older than %d days, and the guest data of %d online check-ins.',
            $audit,
            $workflow,
            $notifications,
            $mcpCalls,
            $days,
            $checkIns,
        ));

        return Command::SUCCESS;
    }
}
