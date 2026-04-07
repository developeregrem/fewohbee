<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\WorkflowLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'workflow:purge-logs',
    description: 'Delete workflow log entries older than a given number of days (run via daily cron).',
)]
class PurgeWorkflowLogsCommand extends Command
{
    public function __construct(
        private readonly WorkflowLogRepository $logRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete log entries older than this many days.', 90);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $input->getOption('days');

        if ($days <= 0) {
            $io->error('--days must be a positive integer.');

            return Command::FAILURE;
        }

        $before = new \DateTimeImmutable('-' . $days . ' days');
        $deleted = $this->logRepository->purgeOlderThan($before);

        $io->success(sprintf('Deleted %d log entries older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
