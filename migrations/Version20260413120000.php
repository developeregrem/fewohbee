<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounting_accounts, tax_rates and accounting_settings tables for booking journal';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE accounting_accounts (
            id INT AUTO_INCREMENT NOT NULL,
            account_number VARCHAR(10) NOT NULL,
            name VARCHAR(150) NOT NULL,
            type VARCHAR(20) NOT NULL,
            is_cash_account TINYINT(1) DEFAULT 0 NOT NULL,
            is_system_default TINYINT(1) DEFAULT 0 NOT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_ACCT_NUMBER (account_number),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE tax_rates (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(80) NOT NULL,
            rate NUMERIC(5, 2) NOT NULL,
            datev_bu_key VARCHAR(4) DEFAULT NULL,
            valid_from DATE DEFAULT NULL,
            valid_to DATE DEFAULT NULL,
            is_default TINYINT(1) DEFAULT 0 NOT NULL,
            revenue_account_id INT DEFAULT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_F7AE5E1D1269BA99 (revenue_account_id),
            CONSTRAINT FK_F7AE5E1D1269BA99 FOREIGN KEY (revenue_account_id) REFERENCES accounting_accounts (id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE accounting_settings (
            id INT AUTO_INCREMENT NOT NULL,
            chart_preset VARCHAR(20) DEFAULT NULL,
            advisor_number VARCHAR(10) DEFAULT NULL,
            client_number VARCHAR(10) DEFAULT NULL,
            fiscal_year_start SMALLINT DEFAULT 1 NOT NULL,
            account_number_length SMALLINT DEFAULT 4 NOT NULL,
            dictation_code VARCHAR(5) DEFAULT \'WD\',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ── booking_batches ──────────────────────────────────────
        $this->addSql('CREATE TABLE booking_batches (
            id INT AUTO_INCREMENT NOT NULL,
            year SMALLINT UNSIGNED NOT NULL,
            month SMALLINT UNSIGNED NOT NULL,
            is_closed TINYINT(1) DEFAULT 0 NOT NULL,
            is_exported TINYINT(1) DEFAULT 0 NOT NULL,
            cash_start NUMERIC(13, 2) DEFAULT NULL,
            cash_end NUMERIC(13, 2) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ── booking_entries ─────────────────────────────────────
        $this->addSql('CREATE TABLE booking_entries (
            id INT AUTO_INCREMENT NOT NULL,
            booking_batch_id INT NOT NULL,
            date DATE NOT NULL,
            document_number INT NOT NULL,
            amount NUMERIC(13, 2) NOT NULL,
            debit_account_id INT DEFAULT NULL,
            credit_account_id INT DEFAULT NULL,
            tax_rate_id INT DEFAULT NULL,
            invoice_number VARCHAR(50) DEFAULT NULL,
            invoice_id INT DEFAULT NULL,
            remark VARCHAR(255) DEFAULT NULL,
            counter_account_legacy VARCHAR(50) DEFAULT NULL,
            source_type VARCHAR(30) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_booking_batch (booking_batch_id),
            INDEX IDX_debit_account (debit_account_id),
            INDEX IDX_credit_account (credit_account_id),
            INDEX IDX_tax_rate (tax_rate_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_be_batch FOREIGN KEY (booking_batch_id) REFERENCES booking_batches (id),
            CONSTRAINT FK_be_debit FOREIGN KEY (debit_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL,
            CONSTRAINT FK_be_credit FOREIGN KEY (credit_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL,
            CONSTRAINT FK_be_taxrate FOREIGN KEY (tax_rate_id) REFERENCES tax_rates (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // ── Data migration from cash_journal ────────────────────
        // 1. Create a default cash account if cash_journal_entries exist (preset hasn't been loaded yet)
        $this->addSql("INSERT INTO accounting_accounts (account_number, name, type, is_cash_account, is_system_default, sort_order, created_at)
            SELECT '1000', 'Kasse', 'asset', 1, 1, 0, NOW()
            FROM cash_journal_entries
            WHERE NOT EXISTS (SELECT 1 FROM accounting_accounts WHERE is_cash_account = 1)
            LIMIT 1");

        // 2. Copy batches
        $this->addSql('INSERT INTO booking_batches (id, year, month, is_closed, is_exported, cash_start, cash_end, created_at)
            SELECT id, cash_year, cash_month, is_closed, is_booked, cash_start, cash_end, NOW()
            FROM cash_journal');

        // 3. Find the cash account
        $this->addSql('SET @cashAccountId = (SELECT id FROM accounting_accounts WHERE is_cash_account = 1 LIMIT 1)');

        // 4. Copy entries: incomes > 0 → debit=cash, amount=incomes
        $this->addSql('INSERT INTO booking_entries (booking_batch_id, date, document_number, amount, debit_account_id, credit_account_id, invoice_number, remark, counter_account_legacy, source_type, created_at)
            SELECT
                cje.cash_journal_id,
                cje.date,
                cje.document_number,
                cje.incomes,
                @cashAccountId,
                NULL,
                cje.invoice_number,
                cje.remark,
                cje.counter_account,
                \'migration\',
                NOW()
            FROM cash_journal_entries cje
            WHERE cje.incomes > 0');

        // 5. Copy entries: expenses > 0 → credit=cash, amount=expenses
        $this->addSql('INSERT INTO booking_entries (booking_batch_id, date, document_number, amount, debit_account_id, credit_account_id, invoice_number, remark, counter_account_legacy, source_type, created_at)
            SELECT
                cje.cash_journal_id,
                cje.date,
                cje.document_number,
                cje.expenses,
                NULL,
                @cashAccountId,
                cje.invoice_number,
                cje.remark,
                cje.counter_account,
                \'migration\',
                NOW()
            FROM cash_journal_entries cje
            WHERE cje.expenses > 0 AND (cje.incomes = 0 OR cje.incomes IS NULL)');

        // 6. Rename old tables (keep as backup)
        $this->addSql('RENAME TABLE cash_journal_entries TO _legacy_cash_journal_entries');
        $this->addSql('RENAME TABLE cash_journal TO _legacy_cash_journal');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE accounting_settings');
        $this->addSql('ALTER TABLE tax_rates DROP FOREIGN KEY FK_F7AE5E1D1269BA99');
        $this->addSql('DROP TABLE tax_rates');
        $this->addSql('DROP TABLE accounting_accounts');

        // Restore old tables
        $this->addSql('RENAME TABLE _legacy_cash_journal TO cash_journal');
        $this->addSql('RENAME TABLE _legacy_cash_journal_entries TO cash_journal_entries');

        // Drop new tables
        $this->addSql('DROP TABLE booking_entries');
        $this->addSql('DROP TABLE booking_batches');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
