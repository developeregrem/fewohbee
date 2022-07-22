<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

use App\Entity\PostalCodeData;

#[AsCommand(
    name: 'app:import-postalcodedata',
    description: 'This command imports postal code data (e.g. from geonames) to be used in autocompletion when entering a zip code while creating a customer\'s address',
)]
class ImportPostalcodedataCommand extends Command
{
    public function __construct(EntityManagerInterface $em, string $name = null) {
        $this->em = $em;
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
            $this->emptyTaple();
            $io->info("Successfully cleared old data.");
        }

        $stream = null;
        $batchSize = 20;
        $i = 0;
        try {
            if(!($stream = fopen($inputFile, "r"))) {
                throw new \RuntimeException("Could not open input file.");
            }
            
            $io->info("Starting import ...");
            while ( ($line = stream_get_line( $stream, 1024, "\n" )) !== false ) {
                $entity = $this->makeEntityFromLine($line);
                if($entity !== null) {                    
                    $this->em->persist($entity);                    
                    $i++;
                }
                if (($i % $batchSize) === 0) {
                    $this->em->flush();
                    $this->em->clear(); // Detaches all objects from Doctrine!
                }
                
            }
            
            $this->em->flush(); // Persist objects that did not make up an entire batch
            $this->em->clear();
            
        } catch (\Exception $ex) {
            throw new \RuntimeException("Could not process input file. " . $ex->getMessage());
        } finally {
            $this->safeClose($stream);            
        }

        $io->success('All done! Imported ' . $i . ' entries.');

        return Command::SUCCESS;
    }
    
    private function makeEntityFromLine(string $line) : ?PostalCodeData {
        /*
         * Columns must be separated by a tab and must contain the following items
         * country code      : iso country code, 2 characters
         * postal code       : varchar(20)
         * place name        : varchar(180)
         * admin name1       : 1. order subdivision (state) varchar(100)
         * admin code1       : 1. order subdivision (state) varchar(20)
         */
        $items = explode("\t", $line);
        $entity = null;

        if (count($items) >= 5) {
            $entity = new PostalCodeData();
            $entity
                ->setCountryCode( $items[0] )                   
                ->setPostalCode( $items[1] )
                ->setPlaceName( $items[2] )
                ->setStateName( $items[3] )
                ->setStateNameShort( $items[4])
            ;
        }
        
        return $entity;
    }
    
    private function safeClose($stream) : void {
        if($stream !== null) {
            try {
                fclose($stream);
            } catch (\Exception $ex) {
                throw new \RuntimeException("Error while closing stream");
            }
        }
    }
    
    private function emptyTaple() {
        $cmd = $this->em->getClassMetadata( PostalCodeData::class );
        $connection = $this->em->getConnection();
        $connection->setAutoCommit(false);
        $connection->beginTransaction();

        try {
            $connection->query('SET FOREIGN_KEY_CHECKS=0');
            $connection->query('DELETE FROM ' . $cmd->getTableName());
            // Beware of ALTER TABLE here--it's another DDL statement and will cause
            // an implicit commit.
            $connection->query('SET FOREIGN_KEY_CHECKS=1');
            $connection->query('ALTER TABLE ' . $cmd->getTableName() . ' AUTO_INCREMENT = 1');
            
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollback();
        }
    }

}
