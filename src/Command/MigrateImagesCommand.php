<?php

declare(strict_types=1);

namespace App\Command;

use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Copies the contents of the legacy local upload directories into the currently
 * configured Flysystem storages (typically S3). Idempotent — existing destination
 * files are skipped unless --overwrite is given.
 *
 * Typical workflow when switching to S3:
 *   1. Set STORAGE_ADAPTER=s3 and STORAGE_S3_* in .env
 *   2. bin/console cache:clear
 *   3. bin/console app:storage:migrate-images
 */
#[AsCommand(name: 'app:storage:migrate-images', description: 'Copy local image uploads into the configured Flysystem storages (e.g. S3).')]
final class MigrateImagesCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'images.export.storage')]
        private readonly FilesystemOperator $exportStorage,
        #[Autowire(service: 'images.roomcat.storage')]
        private readonly FilesystemOperator $roomCatStorage,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite files that already exist at the destination.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be copied, do not write anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $overwrite = (bool) $input->getOption('overwrite');
        $dryRun = (bool) $input->getOption('dry-run');

        $jobs = [
            ['Template uploads (export)', $this->projectDir . '/public/resources/images/export', $this->exportStorage],
            ['Room category images', $this->projectDir . '/public/resources/images/room-categories', $this->roomCatStorage],
        ];

        $totalCopied = 0;
        $totalSkipped = 0;

        foreach ($jobs as [$label, $sourceDir, $storage]) {
            $io->section($label);

            if (!is_dir($sourceDir)) {
                $io->writeln(sprintf('  <comment>Source directory does not exist: %s — nothing to migrate.</comment>', $sourceDir));
                continue;
            }

            $source = new Filesystem(new LocalFilesystemAdapter($sourceDir));

            try {
                $listing = $source->listContents('', deep: true);
            } catch (FilesystemException $e) {
                $io->error(sprintf('Cannot read %s: %s', $sourceDir, $e->getMessage()));
                return Command::FAILURE;
            }

            $copied = 0;
            $skipped = 0;

            foreach ($listing as $item) {
                if (!$item->isFile()) {
                    continue;
                }
                $path = $item->path();

                try {
                    if (!$overwrite && $storage->fileExists($path)) {
                        ++$skipped;
                        continue;
                    }
                } catch (FilesystemException $e) {
                    $io->error(sprintf('Cannot stat destination %s: %s', $path, $e->getMessage()));
                    return Command::FAILURE;
                }

                if ($dryRun) {
                    $io->writeln('  [dry-run] would copy ' . $path);
                    ++$copied;
                    continue;
                }

                try {
                    $stream = $source->readStream($path);
                    $storage->writeStream($path, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    ++$copied;
                } catch (FilesystemException $e) {
                    $io->error(sprintf('Failed to copy %s: %s', $path, $e->getMessage()));
                    return Command::FAILURE;
                }
            }

            $io->writeln(sprintf('  copied: <info>%d</info>, skipped (already present): <info>%d</info>', $copied, $skipped));
            $totalCopied += $copied;
            $totalSkipped += $skipped;
        }

        $io->success(sprintf('Done. %d file(s) copied, %d skipped.', $totalCopied, $totalSkipped));
        return Command::SUCCESS;
    }
}
