<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Configurable invoice number ranges per branch.
 *
 * Adds `invoice_number_pattern` to `objects` (branch override) and `app_settings`
 * (global default), links invoices and invoice issuer data to a branch, and drops the
 * three sample invoice numbers the bank import used to infer a regex from.
 *
 * `invoice_number_pattern` deliberately stays NULL: that means "not configured" and keeps
 * invoice numbering on the previous behaviour of incrementing the most recent number. Bank
 * import invoice matching is off until a range is configured; the bank import screen says
 * so and offers the most recent invoice numbers as orientation. Nothing is reported from
 * here on purpose — updates usually run unattended via cron, so migration output reaches
 * nobody.
 *
 * Existing invoices are assigned to a branch wherever their reservations point at exactly
 * one, so per-branch ranges and issuers work for historic data too.
 *
 * down() restores the schema and seeds one usable sample from the newest invoice number,
 * but the per-branch patterns, the per-branch issuer assignments and the original sample
 * list cannot be reconstructed.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configurable invoice number ranges per subsidiary; link invoices and invoice issuer data to a subsidiary; drop accounting_settings.invoice_number_samples';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE objects ADD invoice_number_pattern VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_settings ADD invoice_number_pattern VARCHAR(100) DEFAULT NULL');

        $this->addSql('ALTER TABLE invoices ADD subsidiary_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD CONSTRAINT FK_6A2F2F9560602D95 FOREIGN KEY (subsidiary_id) REFERENCES objects (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6A2F2F9560602D95 ON invoices (subsidiary_id)');

        // invoices.number had no index at all; both the number range lookup and the bank
        // import's findByNumbers() scan it.
        $this->addSql('CREATE INDEX idx_invoice_number ON invoices (number)');

        $this->addSql('ALTER TABLE invoice_settings_data ADD subsidiary_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data ADD CONSTRAINT FK_9C8E2C8760602D95 FOREIGN KEY (subsidiary_id) REFERENCES objects (id) ON DELETE SET NULL');
        // At most one issuer per branch — enforced by the database, not by convention.
        // MySQL allows any number of NULLs in a unique index, so "no branch" stays free.
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9C8E2C8760602D95 ON invoice_settings_data (subsidiary_id)');

        $this->backfillInvoiceSubsidiaries();

        $this->addSql('ALTER TABLE accounting_settings DROP invoice_number_samples');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounting_settings ADD invoice_number_samples JSON DEFAULT NULL');
        // Seed one working sample from the newest invoice number so bank import matching
        // still has something to infer from after a rollback.
        $this->addSql("UPDATE accounting_settings SET invoice_number_samples =
            (SELECT JSON_ARRAY(i.number) FROM invoices i WHERE i.number <> '' ORDER BY i.id DESC LIMIT 1)");

        $this->addSql('DROP INDEX UNIQ_9C8E2C8760602D95 ON invoice_settings_data');
        $this->addSql('ALTER TABLE invoice_settings_data DROP FOREIGN KEY FK_9C8E2C8760602D95');
        $this->addSql('ALTER TABLE invoice_settings_data DROP subsidiary_id');

        $this->addSql('ALTER TABLE invoices DROP FOREIGN KEY FK_6A2F2F9560602D95');
        $this->addSql('DROP INDEX IDX_6A2F2F9560602D95 ON invoices');
        $this->addSql('DROP INDEX idx_invoice_number ON invoices');
        $this->addSql('ALTER TABLE invoices DROP subsidiary_id');

        $this->addSql('ALTER TABLE app_settings DROP invoice_number_pattern');
        $this->addSql('ALTER TABLE objects DROP invoice_number_pattern');
    }

    /**
     * DDL and DML in one migration; MySQL cannot roll DDL back anyway.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    /**
     * Assigns existing invoices to a branch, but only where all their reservations point
     * at the same one. Cross-branch invoices and invoices without reservations stay NULL,
     * which correctly means "global range and global issuer".
     */
    private function backfillInvoiceSubsidiaries(): void
    {
        $this->addSql('UPDATE invoices i
            INNER JOIN (
                SELECT ri.invoice_id               AS invoice_id,
                       MIN(a.object_id)            AS object_id,
                       COUNT(DISTINCT a.object_id) AS branch_count
                FROM reservations_has_invoices ri
                INNER JOIN reservations r ON r.id = ri.reservation_id
                INNER JOIN appartments  a ON a.id = r.appartment_id
                WHERE a.object_id IS NOT NULL
                GROUP BY ri.invoice_id
            ) m ON m.invoice_id = i.id
            SET i.subsidiary_id = m.object_id
            WHERE m.branch_count = 1');
    }
}
