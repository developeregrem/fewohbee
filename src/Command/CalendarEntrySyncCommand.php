<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CalendarRepository;
use App\Service\CalendarEntrySyncService;
use App\Service\Exception\CalendarSyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'calendars:sync',
    description: 'Synchronize all calendars that have an ICS upload or URL configured.',
)]
class CalendarEntrySyncCommand extends Command
{
    public function __construct(
        private readonly CalendarRepository $calendarRepository,
        private readonly CalendarEntrySyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $calendars = $this->calendarRepository->findWithIcsSource();

        if ([] === $calendars) {
            $io->comment('No calendars with an ICS source configured, skipping.');

            return Command::SUCCESS;
        }

        $hadFailure = false;
        foreach ($calendars as $calendar) {
            try {
                $result = $this->syncService->sync($calendar);
                $io->writeln(sprintf(
                    '%s: %d new, %d updated, %d unchanged.',
                    $calendar->getName(),
                    $result->new,
                    $result->updated,
                    $result->unchanged,
                ));

                // Not a failure - the run stays successful - but silently
                // importing nothing from a recurring feed is what makes this
                // confusing in the first place, so it gets said out loud.
                if ($result->skippedRecurring > 0) {
                    $io->warning(sprintf(
                        '%s: %d recurring event(s) skipped, RRULE is not expanded.',
                        $calendar->getName(),
                        $result->skippedRecurring,
                    ));
                }
            } catch (CalendarSyncException $e) {
                $hadFailure = true;
                $io->error(sprintf('%s: %s', $calendar->getName(), $e->getMessage()));
            }
        }

        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
