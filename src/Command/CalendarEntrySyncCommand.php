<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\CalendarRepository;
use App\Service\Calendar\Sync\CalendarEntrySyncService;
use App\Exception\CalendarSyncException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'calendars:sync',
    description: 'Synchronize all calendars that have an ICS upload or URL configured.',
)]
class CalendarEntrySyncCommand extends Command
{
    public function __construct(
        private readonly CalendarRepository $calendarRepository,
        private readonly CalendarEntrySyncService $syncService,
        private readonly TranslatorInterface $translator,
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

                // Not a failure - the run stays successful - but an event the
                // import quietly dropped looks like the calendar lost it, so
                // it gets said out loud.
                if ($result->skippedInvalid > 0) {
                    $io->warning(sprintf(
                        '%s: %d event(s) skipped, their dates could not be read.',
                        $calendar->getName(),
                        $result->skippedInvalid,
                    ));
                }
            } catch (CalendarSyncException $e) {
                $hadFailure = true;
                $io->error(sprintf('%s: %s', $calendar->getName(), $this->translator->trans($e->translationKey, $e->translationParameters)));
            }
        }

        return $hadFailure ? Command::FAILURE : Command::SUCCESS;
    }
}
