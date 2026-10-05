<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * In-progress bank statement imports move from the browser session into the database, so they
 * survive a logout and AI assistants can work on them. Drafts in running sessions are not carried
 * over; such an import has to be uploaded again after the update.
 */
final class Version20260929110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store bank statement import drafts in the database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_import_drafts (id VARCHAR(36) NOT NULL, state JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_bank_import_drafts_updated (updated_at), INDEX IDX_1ABE0FD3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bank_import_drafts ADD CONSTRAINT FK_1ABE0FD3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    /**
     * Drops unfinished drafts; they only hold not yet committed statement lines.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_import_drafts DROP FOREIGN KEY FK_1ABE0FD3A76ED395');
        $this->addSql('DROP TABLE bank_import_drafts');
    }
}
