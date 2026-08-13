<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add theme column to online booking config; keep installations that already use online booking on the classic design';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE online_booking_config ADD theme VARCHAR(20) DEFAULT 'modern' NOT NULL");
        // Properties already running online booking keep the previous design, because their
        // custom CSS was written against its markup. New installations get the modern theme.
        $this->addSql("UPDATE online_booking_config SET theme = 'classic' WHERE enabled = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_booking_config DROP theme');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
