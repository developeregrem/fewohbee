<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gives calendar entries an optional time of day.
 */
final class Version20260816120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional start and end time to calendar entries (both null keeps an entry all-day)';
    }

    public function up(Schema $schema): void
    {
        // Nullable without a default, so every existing row stays exactly what
        // it was: an all-day entry, which is the only kind that existed before.
        $this->addSql('ALTER TABLE calendar_entry ADD time TIME DEFAULT NULL, ADD end_time TIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP time, DROP end_time');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
