<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the kind of room (accommodation or event space) to room categories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE room_category ADD kind VARCHAR(20) DEFAULT 'accommodation' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Without the column an event space would count as accommodation again: in occupancy,
        // overnight stays, tourist tax and online booking.
        $used = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM room_category WHERE kind <> 'accommodation'");
        $this->abortIf($used > 0, 'Change all room categories of the kind "event space" back to "accommodation" before downgrading.');

        $this->addSql('ALTER TABLE room_category DROP kind');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
