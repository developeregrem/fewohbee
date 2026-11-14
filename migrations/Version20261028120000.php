<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Event spaces: a room category is either accommodation or an event space, booked by the day.
 * Invoice positions remember whether their room was an event space.
 */
final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the kind of room (accommodation or event space) to room categories and remember it on invoice positions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE room_category ADD kind VARCHAR(20) DEFAULT 'accommodation' NOT NULL");
        $this->addSql('ALTER TABLE invoice_appartments ADD event_space TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Without the column an event space would count as accommodation again: in occupancy,
        // overnight stays, tourist tax and online booking.
        $used = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM room_category WHERE kind <> 'accommodation'");
        $this->abortIf($used > 0, 'Change all room categories of the kind "event space" back to "accommodation" before downgrading.');
        // Without the column a position of an event space would show the day after the event as its end.
        $positions = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoice_appartments WHERE event_space = 1');
        $this->abortIf($positions > 0, 'Invoice positions of event spaces exist; downgrading would show the day after each event as its end.');

        $this->addSql('ALTER TABLE invoice_appartments DROP event_space');
        $this->addSql('ALTER TABLE room_category DROP kind');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
