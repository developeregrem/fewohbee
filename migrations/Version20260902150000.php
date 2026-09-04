<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stores user-confirmed calendar exclusions and whether they are shared by portal host.
 */
final class Version20260902150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add calendar-label exclusions and cross-room sharing preferences to iCal reservation imports';
    }

    public function up(Schema $schema): void
    {
        // Nullable JSON keeps the migration safe for existing rows; the entity treats NULL as an empty list.
        $this->addSql('ALTER TABLE calendar_sync_import ADD excluded_summaries JSON DEFAULT NULL, ADD excluded_summary_terms JSON DEFAULT NULL, ADD share_summary_filters TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_sync_import DROP excluded_summaries, DROP excluded_summary_terms, DROP share_summary_filters');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
