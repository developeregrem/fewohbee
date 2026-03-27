<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PostalCodeData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-postalcodedata',
    description: 'This command imports postal code data (e.g. from geonames) to be used in autocompletion when entering a zip code while creating a customer\'s address',
)]
class ImportPostalcodedataCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(private readonly EntityManagerInterface $em, ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'The file containing the data.')
            ->addOption('override', 'o', InputOption::VALUE_NONE, 'Whether to override an older import.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $inputFile = $input->getArgument('file');
        $override = $input->getOption('override');

        if ($override) {
            $this->emptyTable();
            $io->info('Successfully cleared old data.');
        }

        $stream = null;
        $connection = $this->em->getConnection();
        $nativeConnection = $connection->getNativeConnection();
        $importedRows = 0;
        $pendingRows = 0;
        $insertSql = sprintf(
            'INSERT INTO %s (country_code, postal_code, place_name, state_name, state_name_short) VALUES (?, ?, ?, ?, ?)',
            $this->em->getClassMetadata(PostalCodeData::class)->getTableName()
        );

        try {
            if (!$nativeConnection instanceof \PDO) {
                throw new \RuntimeException('Native database connection is not a PDO instance.');
            }

            if (!($stream = fopen($inputFile, 'r'))) {
                throw new \RuntimeException(sprintf('Could not open input file "%s".', $inputFile));
            }

            $stmt = $nativeConnection->prepare($insertSql);

            if (false === $stmt) {
                throw new \RuntimeException('Could not prepare insert statement.');
            }

            $io->info('Starting import ...');
            $nativeConnection->beginTransaction();

            while (($line = fgets($stream)) !== false) {
                $row = $this->makeRowFromLine($line);

                if (null !== $row) {
                    $stmt->execute(array_values($row));
                    ++$importedRows;
                    ++$pendingRows;
                }

                if ($pendingRows >= self::BATCH_SIZE) {
                    $nativeConnection->commit();
                    $nativeConnection->beginTransaction();
                    $pendingRows = 0;
                }
            }

            if (!feof($stream)) {
                throw new \RuntimeException(sprintf('Error while reading input file "%s".', $inputFile));
            }

            $nativeConnection->commit();
        } catch (\Throwable $ex) {
            if ($nativeConnection instanceof \PDO && $nativeConnection->inTransaction()) {
                $nativeConnection->rollBack();
            }

            throw new \RuntimeException('Could not process input file. '.$ex->getMessage(), 0, $ex);
        } finally {
            $this->safeClose($stream);
        }

        $io->success('All done! Imported '.$importedRows.' entries.');

        return Command::SUCCESS;
    }

    /**
     * Columns must be separated by a tab and must contain the following items:
     * country code, postal code, place name, admin name1, admin code1.
     *
     * @return array<string, string|null>|null
     */
    private function makeRowFromLine(string $line): ?array
    {
        $items = explode("\t", rtrim($line, "\r\n"));

        if (count($items) < 5) {
            return null;
        }

        return [
            'countryCode' => $items[0],
            'postalCode' => $items[1],
            'placeName' => $items[2],
            'stateName' => $this->normalizeNullableValue($items[3]),
            'stateNameShort' => $this->normalizeNullableValue($items[4]),
        ];
    }

    private function normalizeNullableValue(string $value): ?string
    {
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param resource|null $stream
     */
    private function safeClose($stream): void
    {
        if (null !== $stream) {
            try {
                fclose($stream);
            } catch (\Throwable $ex) {
                throw new \RuntimeException('Error while closing stream.', 0, $ex);
            }
        }
    }

    private function emptyTable(): void
    {
        $cmd = $this->em->getClassMetadata(PostalCodeData::class);
        $connection = $this->em->getConnection();
        $platform = $connection->getDatabasePlatform();
        $tableName = $cmd->getTableName();

        try {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            $connection->executeStatement($platform->getTruncateTableSQL($tableName, true));
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Could not clear table "%s".', $tableName), 0, $e);
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
