<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bookkeeping work that waits for a person to finish it. New tables only, nothing existing changes.
 *
 * - receipt_proposals: receipts an AI assistant read and handed in, waiting for a person to book
 *   them (see App\Entity\ReceiptProposal);
 * - bank_import_drafts: in-progress bank statement imports move from the browser session into the
 *   database, so they survive a logout and AI assistants can work on them. Drafts in running
 *   sessions are not carried over; such an import has to be uploaded again after the update.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add receipt proposals handed in by AI assistants and store bank statement import drafts in the database';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE receipt_proposals (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) DEFAULT \'open\' NOT NULL, supplier VARCHAR(150) NOT NULL, receipt_date DATE NOT NULL, receipt_number VARCHAR(50) DEFAULT NULL, total NUMERIC(13, 2) NOT NULL, payment VARCHAR(10) DEFAULT \'unknown\' NOT NULL, receipt_lines JSON NOT NULL, note LONGTEXT DEFAULT NULL, token_prefix VARCHAR(12) DEFAULT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, booked_entry_ids JSON DEFAULT NULL, submitted_by_id INT DEFAULT NULL, decided_by_id INT DEFAULT NULL, INDEX idx_receipt_proposal_status (status), INDEX IDX_8939B29179F7D87D (submitted_by_id), INDEX IDX_8939B291E26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE receipt_proposals ADD CONSTRAINT FK_8939B29179F7D87D FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE receipt_proposals ADD CONSTRAINT FK_8939B291E26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE bank_import_drafts (id VARCHAR(36) NOT NULL, state JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_bank_import_drafts_updated (updated_at), INDEX IDX_1ABE0FD3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bank_import_drafts ADD CONSTRAINT FK_1ABE0FD3A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    /**
     * Refused while receipt proposals exist; they may still await a decision. Unfinished bank
     * import drafts are dropped; they only hold not yet committed statement lines.
     */
    public function down(Schema $schema): void
    {
        $proposals = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_proposals');
        $this->abortIf($proposals > 0, 'Cannot roll back receipt proposals while the table contains data.');

        $this->addSql('ALTER TABLE bank_import_drafts DROP FOREIGN KEY FK_1ABE0FD3A76ED395');
        $this->addSql('DROP TABLE bank_import_drafts');

        $this->addSql('DROP TABLE receipt_proposals');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
