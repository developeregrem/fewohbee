<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Receipt proposals: receipts an AI assistant read and handed in, waiting for a person to book
 * them (see App\Entity\ReceiptProposal). A new table only, nothing existing changes.
 */
final class Version20260928120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add receipt proposals handed in by AI assistants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE receipt_proposals (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(16) DEFAULT \'open\' NOT NULL, supplier VARCHAR(150) NOT NULL, receipt_date DATE NOT NULL, receipt_number VARCHAR(50) DEFAULT NULL, total NUMERIC(13, 2) NOT NULL, payment VARCHAR(10) DEFAULT \'unknown\' NOT NULL, receipt_lines JSON NOT NULL, note LONGTEXT DEFAULT NULL, token_prefix VARCHAR(12) DEFAULT NULL, created_at DATETIME NOT NULL, decided_at DATETIME DEFAULT NULL, booked_entry_ids JSON DEFAULT NULL, submitted_by_id INT DEFAULT NULL, decided_by_id INT DEFAULT NULL, INDEX idx_receipt_proposal_status (status), INDEX IDX_8939B29179F7D87D (submitted_by_id), INDEX IDX_8939B291E26B496B (decided_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE receipt_proposals ADD CONSTRAINT FK_8939B29179F7D87D FOREIGN KEY (submitted_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE receipt_proposals ADD CONSTRAINT FK_8939B291E26B496B FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    /** Refuse rollback while proposals exist; they may still await a decision. */
    public function down(Schema $schema): void
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM receipt_proposals') > 0) {
            throw new \RuntimeException('Cannot roll back receipt proposals while the table contains data.');
        }
        $this->addSql('DROP TABLE receipt_proposals');
    }
}
