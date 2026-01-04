<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Subsidiary;
use App\Service\MonthlyStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stats:snapshot:month',
    description: 'Create or update a monthly statistics snapshot.',
)]
class MonthlyStatsSnapshotCommand extends Command
{
    /**
     * Configure the command with dependencies.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MonthlyStatsService $monthlyStatsService
    ) {
        parent::__construct();
    }

    /**
     * Define command options for snapshot generation.
     */
    protected function configure(): void
    {
        $this
            ->addOption('month', null, InputOption::VALUE_OPTIONAL, 'Month number (1-12). Defaults to previous month.')
            ->addOption('year', null, InputOption::VALUE_OPTIONAL, 'Year (4-digit). Defaults to previous month.')
            ->addOption('subsidiary', null, InputOption::VALUE_OPTIONAL, 'Subsidiary id or "all". Defaults to all.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing snapshot.');
    }

    /**
     * Run the snapshot generation process.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $monthOpt = $input->getOption('month');
        $yearOpt = $input->getOption('year');
        $subsidiaryOpt = $input->getOption('subsidiary');
        $force = (bool) $input->getOption('force');

        $targetDate = new \DateTimeImmutable('first day of this month');
        if (null === $monthOpt || null === $yearOpt) {
            $targetDate = $targetDate->modify('-1 month');
        }

        $month = (int) ($monthOpt ?? $targetDate->format('n'));
        $year = (int) ($yearOpt ?? $targetDate->format('Y'));

        if ($month < 1 || $month > 12) {
            $io->error('Month must be between 1 and 12.');

            return Command::INVALID;
        }

        $subsidiary = null;
        if (null !== $subsidiaryOpt && 'all' !== $subsidiaryOpt) {
            $subsidiary = $this->em->getRepository(Subsidiary::class)->find($subsidiaryOpt);
            if (null === $subsidiary) {
                $io->error('Subsidiary not found.');

                return Command::INVALID;
            }
        }

        $payload = $this->monthlyStatsService->getOrCreateSnapshotWithWarnings($month, $year, $subsidiary, $force);
        $snapshot = $payload['snapshot'];
        $warnings = $payload['warnings'];

        $io->success(sprintf(
            'Snapshot saved for %02d/%04d (%s).',
            $snapshot->getMonth(),
            $snapshot->getYear(),
            $subsidiary?->getName() ?? 'all'
        ));
        foreach ($warnings as $warning) {
            $io->warning(sprintf(
                '%s %s -> %s',
                $warning['message'] ?? 'Warning',
                $warning['start_date'] ?? '',
                $warning['end_date'] ?? ''
            ));
        }

        return Command::SUCCESS;
    }
}
