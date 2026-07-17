<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create calendar and calendar_entry tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(20) NOT NULL,
            requires_confirmation TINYINT(1) NOT NULL,
            ics_url VARCHAR(255) DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            last_sync_count INT DEFAULT NULL,
            last_sync_new_count INT DEFAULT NULL,
            last_sync_updated_count INT DEFAULT NULL,
            last_sync_unchanged_count INT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE calendar_entry (
            id INT AUTO_INCREMENT NOT NULL,
            calendar_id INT NOT NULL,
            confirmed_by_id INT DEFAULT NULL,
            date DATE NOT NULL,
            title VARCHAR(100) NOT NULL,
            ics_uid VARCHAR(255) NOT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_calendar_entry_ics_uid (ics_uid),
            INDEX idx_calendar_entry_date (date),
            INDEX IDX_CALENDAR_ENTRY_CALENDAR (calendar_id),
            INDEX IDX_CALENDAR_ENTRY_CONFIRMED_BY (confirmed_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE calendar_entry ADD CONSTRAINT FK_CALENDAR_ENTRY_CALENDAR FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_entry ADD CONSTRAINT FK_CALENDAR_ENTRY_CONFIRMED_BY FOREIGN KEY (confirmed_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE calendar_entry');
        $this->addSql('DROP TABLE calendar');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
