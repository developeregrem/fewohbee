<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remember on invoice positions whether the room was an event space (booked by the day)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_appartments ADD event_space TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Without the column a position of an event space would show the day after the event as its end.
        $used = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoice_appartments WHERE event_space = 1');
        $this->abortIf($used > 0, 'Invoice positions of event spaces exist; downgrading would show the day after each event as its end.');

        $this->addSql('ALTER TABLE invoice_appartments DROP event_space');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
