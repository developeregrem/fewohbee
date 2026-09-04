<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Check-in and check-out times per branch.
 *
 * These are house policy — the window during which a guest can arrive and the time by
 * which the room has to be vacated. They sit next to the opening hours because they are
 * the same kind of data: master data of the branch that guests are told about.
 *
 * Deliberately not related to `reservations.arrival_time` / `departure_time`. Those hold
 * what a particular guest announced for their own stay, which is a statement about one
 * booking, not about the house. Nothing here defaults or overwrites them.
 *
 * TIME columns rather than strings: the values are times, and storing them as such keeps
 * comparisons ("is check-out before check-in?") meaningful without parsing.
 *
 * down() drops the columns and with them the configured times.
 */
final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add check-in window, check-out time and their note to objects (per-subsidiary house policy)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objects ADD check_in_from TIME DEFAULT NULL');
        $this->addSql('ALTER TABLE objects ADD check_in_until TIME DEFAULT NULL');
        $this->addSql('ALTER TABLE objects ADD check_out_until TIME DEFAULT NULL');
        $this->addSql('ALTER TABLE objects ADD check_in_note LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objects DROP check_in_note');
        $this->addSql('ALTER TABLE objects DROP check_out_until');
        $this->addSql('ALTER TABLE objects DROP check_in_until');
        $this->addSql('ALTER TABLE objects DROP check_in_from');
    }
}
