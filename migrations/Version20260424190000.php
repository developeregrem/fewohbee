<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 bank import: new tables bank_csv_profiles, bank_import_rules, bank_statement_imports, bank_import_fingerprints; add iban to accounting_accounts, invoice_number_samples to accounting_settings, split_group_uuid to booking_entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_csv_profiles (
            id                          INT AUTO_INCREMENT NOT NULL,
            name                        VARCHAR(100) NOT NULL,
            description                 LONGTEXT DEFAULT NULL,
            delimiter                   VARCHAR(3) NOT NULL,
            enclosure                   VARCHAR(1) NOT NULL,
            encoding                    VARCHAR(20) NOT NULL,
            header_skip                 INT NOT NULL DEFAULT 0,
            has_header_row              TINYINT(1) NOT NULL DEFAULT 1,
            column_map                  JSON NOT NULL,
            date_format                 VARCHAR(20) NOT NULL,
            amount_decimal_separator    VARCHAR(1) NOT NULL,
            amount_thousands_separator  VARCHAR(1) DEFAULT NULL,
            direction_mode              VARCHAR(20) NOT NULL,
            iban_source_line            INT DEFAULT NULL,
            period_source_line          INT DEFAULT NULL,
            created_at                  DATETIME NOT NULL,
            updated_at                  DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bank_import_rules (
            id              INT AUTO_INCREMENT NOT NULL,
            bank_account_id INT DEFAULT NULL,
            name            VARCHAR(150) NOT NULL,
            description     LONGTEXT DEFAULT NULL,
            priority        INT NOT NULL DEFAULT 0,
            is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
            conditions      JSON NOT NULL,
            action          JSON NOT NULL,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL,
            INDEX idx_bank_import_rule_enabled (is_enabled),
            INDEX idx_bank_import_rule_priority (priority),
            INDEX IDX_bank_import_rule_account (bank_account_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_bank_import_rule_account FOREIGN KEY (bank_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bank_statement_imports (
            id                   INT AUTO_INCREMENT NOT NULL,
            bank_account_id      INT NOT NULL,
            created_by_id        INT DEFAULT NULL,
            period_from          DATE DEFAULT NULL,
            period_to            DATE DEFAULT NULL,
            line_count_total     INT NOT NULL DEFAULT 0,
            line_count_committed INT NOT NULL DEFAULT 0,
            line_count_ignored   INT NOT NULL DEFAULT 0,
            line_count_duplicate INT NOT NULL DEFAULT 0,
            file_format          VARCHAR(20) NOT NULL,
            status               VARCHAR(20) NOT NULL,
            created_at           DATETIME NOT NULL,
            committed_at         DATETIME DEFAULT NULL,
            INDEX idx_bank_statement_import_account (bank_account_id),
            INDEX idx_bank_statement_import_status (status),
            INDEX IDX_bank_statement_import_user (created_by_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_bank_statement_import_account FOREIGN KEY (bank_account_id) REFERENCES accounting_accounts (id),
            CONSTRAINT FK_bank_statement_import_user FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE bank_import_fingerprints (
            id                  INT AUTO_INCREMENT NOT NULL,
            bank_account_id     INT NOT NULL,
            booking_entry_id    INT DEFAULT NULL,
            statement_import_id INT DEFAULT NULL,
            raw_hash            VARCHAR(64) NOT NULL,
            committed_at        DATETIME NOT NULL,
            UNIQUE INDEX uq_bank_fingerprint (bank_account_id, raw_hash),
            INDEX IDX_bank_fingerprint_entry (booking_entry_id),
            INDEX IDX_bank_fingerprint_import (statement_import_id),
            PRIMARY KEY (id),
            CONSTRAINT FK_bank_fingerprint_account FOREIGN KEY (bank_account_id) REFERENCES accounting_accounts (id) ON DELETE CASCADE,
            CONSTRAINT FK_bank_fingerprint_entry FOREIGN KEY (booking_entry_id) REFERENCES booking_entries (id) ON DELETE SET NULL,
            CONSTRAINT FK_bank_fingerprint_import FOREIGN KEY (statement_import_id) REFERENCES bank_statement_imports (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE accounting_accounts ADD iban VARCHAR(34) DEFAULT NULL');
        $this->addSql('ALTER TABLE accounting_settings ADD invoice_number_samples JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE booking_entries ADD split_group_uuid VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_booking_entry_split_group ON booking_entries (split_group_uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_import_fingerprints DROP FOREIGN KEY FK_bank_fingerprint_account');
        $this->addSql('ALTER TABLE bank_import_fingerprints DROP FOREIGN KEY FK_bank_fingerprint_entry');
        $this->addSql('ALTER TABLE bank_import_fingerprints DROP FOREIGN KEY FK_bank_fingerprint_import');
        $this->addSql('ALTER TABLE bank_import_rules DROP FOREIGN KEY FK_bank_import_rule_account');
        $this->addSql('ALTER TABLE bank_statement_imports DROP FOREIGN KEY FK_bank_statement_import_account');
        $this->addSql('ALTER TABLE bank_statement_imports DROP FOREIGN KEY FK_bank_statement_import_user');
        $this->addSql('DROP TABLE bank_csv_profiles');
        $this->addSql('DROP TABLE bank_import_rules');
        $this->addSql('DROP TABLE bank_statement_imports');
        $this->addSql('DROP TABLE bank_import_fingerprints');
        $this->addSql('ALTER TABLE accounting_accounts DROP COLUMN iban');
        $this->addSql('ALTER TABLE accounting_settings DROP COLUMN invoice_number_samples');
        $this->addSql('DROP INDEX idx_booking_entry_split_group ON booking_entries');
        $this->addSql('ALTER TABLE booking_entries DROP COLUMN split_group_uuid');
    }
}
