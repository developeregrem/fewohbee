<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add room calendar export options for reservation times and past entries, defaulting to current and future entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_sync ADD export_reservation_times TINYINT(1) DEFAULT 0 NOT NULL, ADD export_past_entries TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $unsupported = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM calendar_sync WHERE export_reservation_times = 1 OR export_past_entries = 0');
        $this->abortIf($unsupported > 0, 'Disable reservation time export and select the entire calendar for all rooms before downgrading.');

        $this->addSql('ALTER TABLE calendar_sync DROP export_reservation_times, DROP export_past_entries');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
