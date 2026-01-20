<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\CalendarSyncImport;
use App\Service\CalendarImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'calendar:import:sync',
    description: 'Synchronize remote iCal imports.',
)]
class CalendarImportSyncCommand extends Command
{
    /** Provide dependencies for calendar import synchronization. */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalendarImportService $calendarImportService
    ) {
        parent::__construct();
    }

    /** Configure options for targeted or full sync runs. */
    protected function configure(): void
    {
        $this
            ->addOption('import-id', null, InputOption::VALUE_OPTIONAL, 'Sync only a specific import by id.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even if a recent sync was executed.');
    }

    /** Execute the calendar import sync process. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $importId = $input->getOption('import-id');
        $force = (bool) $input->getOption('force');

        if (null !== $importId) {
            $import = $this->em->getRepository(CalendarSyncImport::class)->find($importId);
            if (!$import instanceof CalendarSyncImport) {
                $io->error('Import configuration not found.');

                return Command::INVALID;
            }
            $this->calendarImportService->syncImport($import);
            $io->success(sprintf('Import %d synchronized.', $import->getId()));

            return Command::SUCCESS;
        }

        $this->calendarImportService->syncActiveImports($force);
        $io->success('Active imports synchronized.');

        return Command::SUCCESS;
    }
}
