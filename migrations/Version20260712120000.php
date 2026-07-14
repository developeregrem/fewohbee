<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add waste calendar ICS settings and waste_collection_date table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_settings ADD waste_calendar_ics_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_settings ADD waste_calendar_ics_filename VARCHAR(255) DEFAULT NULL');

        $this->addSql('CREATE TABLE waste_collection_date (
            id INT AUTO_INCREMENT NOT NULL,
            date DATE NOT NULL,
            type VARCHAR(100) NOT NULL,
            ics_uid VARCHAR(255) NOT NULL,
            UNIQUE INDEX uniq_waste_collection_ics_uid (ics_uid),
            INDEX idx_waste_collection_date (date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waste_collection_date');
        $this->addSql('ALTER TABLE app_settings DROP waste_calendar_ics_url');
        $this->addSql('ALTER TABLE app_settings DROP waste_calendar_ics_filename');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
