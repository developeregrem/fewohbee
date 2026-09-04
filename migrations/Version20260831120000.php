<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Opening hours per branch.
 *
 * Adds `opening_hours` to `objects`, holding a JSON map of ISO weekday (1 = Monday) to a
 * list of [from, to] time ranges in 'HH:MM' notation. A weekday missing from the map is
 * closed; NULL means no opening hours were ever configured.
 *
 * A JSON column rather than a table because this is display data: no query asks whether a
 * branch is open at a given moment, so there is nothing to index or join against. Should
 * opening hours ever gain behaviour — blocking availability, constraining arrivals — that
 * decision deserves its own table and its own migration.
 *
 * down() drops the column and with it the configured hours; they cannot be reconstructed.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add opening_hours and opening_hours_note to objects (per-subsidiary opening hours, display only)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objects ADD opening_hours JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE objects ADD opening_hours_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objects DROP opening_hours_note');
        $this->addSql('ALTER TABLE objects DROP opening_hours');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
