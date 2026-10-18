<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an opt-in export of reservation arrival and departure times to room calendars';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_sync ADD export_reservation_times TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $enabled = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM calendar_sync WHERE export_reservation_times = 1');
        $this->abortIf($enabled > 0, 'Disable reservation time export for all rooms before downgrading.');

        $this->addSql('ALTER TABLE calendar_sync DROP export_reservation_times');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
