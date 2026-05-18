<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\LogRepository;
use App\Repository\WorkflowLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-logs',
    description: 'Delete audit and workflow log entries older than a given number of days (run via daily cron).',
    aliases: ['workflow:purge-logs'],
)]
class PurgeLogsCommand extends Command
{
    public function __construct(
        private readonly LogRepository $logRepository,
        private readonly WorkflowLogRepository $workflowLogRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete audit and workflow log entries older than this many days.', 90);
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

        $io->success(sprintf(
            'Deleted %d audit log and %d workflow log entries older than %d days.',
            $audit,
            $workflow,
            $days,
        ));

        return Command::SUCCESS;
    }
}
