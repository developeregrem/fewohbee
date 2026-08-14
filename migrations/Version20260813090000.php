<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

final class Version20260813090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add room UUIDs for the public availability calendar and the booking entry mode on the online booking config';
    }

    public function up(Schema $schema): void
    {
        // Rooms are addressed by UUID on the unauthenticated calendar endpoint so the
        // public surface never exposes sequential ids.
        $this->addSql('ALTER TABLE appartments ADD uuid BINARY(16) DEFAULT NULL');

        foreach ($this->connection->fetchAllAssociative('SELECT id FROM appartments') as $row) {
            $this->addSql('UPDATE appartments SET uuid = ? WHERE id = ?', [Uuid::v4()->toBinary(), $row['id']]);
        }

        $this->addSql('ALTER TABLE appartments CHANGE uuid uuid BINARY(16) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A67E5E3AD17F50A6 ON appartments (uuid)');

        // Entry point for guests: the classic search, or the availability calendar.
        // Mutually exclusive, so a single column rather than a set of toggles.
        $this->addSql("ALTER TABLE online_booking_config ADD mode VARCHAR(20) DEFAULT 'search' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_A67E5E3AD17F50A6 ON appartments');
        $this->addSql('ALTER TABLE appartments DROP uuid');
        $this->addSql('ALTER TABLE online_booking_config DROP mode');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
