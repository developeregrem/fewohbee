<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'calendar_entry.confirmed_by_id: SET NULL on user deletion instead of blocking it';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP FOREIGN KEY FK_CALENDAR_ENTRY_CONFIRMED_BY');
        $this->addSql('ALTER TABLE calendar_entry ADD CONSTRAINT FK_CALENDAR_ENTRY_CONFIRMED_BY FOREIGN KEY (confirmed_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP FOREIGN KEY FK_CALENDAR_ENTRY_CONFIRMED_BY');
        $this->addSql('ALTER TABLE calendar_entry ADD CONSTRAINT FK_CALENDAR_ENTRY_CONFIRMED_BY FOREIGN KEY (confirmed_by_id) REFERENCES users (id)');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
