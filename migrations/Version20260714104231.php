<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260714104231 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track which user confirmed a calendar entry reminder';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry ADD confirmed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_entry ADD CONSTRAINT FK_CALENDAR_ENTRY_CONFIRMED_BY FOREIGN KEY (confirmed_by_id) REFERENCES users (id)');
        $this->addSql('CREATE INDEX IDX_CALENDAR_ENTRY_CONFIRMED_BY ON calendar_entry (confirmed_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP FOREIGN KEY FK_CALENDAR_ENTRY_CONFIRMED_BY');
        $this->addSql('DROP INDEX IDX_CALENDAR_ENTRY_CONFIRMED_BY ON calendar_entry');
        $this->addSql('ALTER TABLE calendar_entry DROP confirmed_by_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
