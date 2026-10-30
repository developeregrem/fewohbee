<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the surcharge for every further person to per-room prices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prices ADD extra_person_surcharge NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // Without the column such a price would apply to its base occupancy only, so stays with
        // more guests would silently lose their price.
        $used = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM prices WHERE extra_person_surcharge IS NOT NULL');
        $this->abortIf($used > 0, 'Remove the "surcharge for every further person" from all prices before downgrading.');

        $this->addSql('ALTER TABLE prices DROP extra_person_surcharge');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
