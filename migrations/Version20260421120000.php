<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split tax_rates.datev_bu_key into datev output/input BU keys; add Automatikkonto and DATEV Sachverhalt/Funktionsergänzung L+L to accounting_accounts';
    }

    public function up(Schema $schema): void
    {
        // ── tax_rates ─────────────────────────────────────────────
        $this->addSql('ALTER TABLE tax_rates
            ADD datev_output_bu_key VARCHAR(4) DEFAULT NULL,
            ADD datev_input_bu_key VARCHAR(4) DEFAULT NULL');

        // Data migration: old datev_bu_key → datev_output_bu_key (was meant as Umsatzsteuer-Key on revenue).
        // For known DE standard rates (7%, 19%), derive the matching input (Vorsteuer) key so existing
        // expense bookings with the same rate now pick up the correct key.
        $this->addSql('UPDATE tax_rates SET datev_output_bu_key = datev_bu_key WHERE datev_bu_key IS NOT NULL');
        $this->addSql("UPDATE tax_rates SET datev_input_bu_key = '8' WHERE rate = 7.00");
        $this->addSql("UPDATE tax_rates SET datev_input_bu_key = '9' WHERE rate = 19.00");

        $this->addSql('ALTER TABLE tax_rates DROP datev_bu_key');

        // ── accounting_accounts ──────────────────────────────────
        $this->addSql('ALTER TABLE accounting_accounts
            ADD is_auto_account TINYINT(1) DEFAULT 0 NOT NULL,
            ADD datev_sachverhalt_l_u_l SMALLINT DEFAULT NULL,
            ADD datev_funktionsergaenzung_l_u_l SMALLINT DEFAULT NULL');

        // Backfill für bereits geladene SKR03/SKR04-Presets:
        // DATEV-Automatikkonten (Erlöse Beherbergung/Standard, Anzahlungen, §13b-Konten).
        $this->addSql("UPDATE accounting_accounts SET is_auto_account = 1 WHERE account_number IN (
            '8300','8400','1717','1718','3123',
            '4300','4400','3271','3272','5923'
        )");

        // §13b EU-Reverse-Charge-Konten erhalten Sachverhalt/Funktionsergänzung L+L.
        $this->addSql("UPDATE accounting_accounts
            SET datev_sachverhalt_l_u_l = 7, datev_funktionsergaenzung_l_u_l = 190
            WHERE account_number IN ('3123','5923')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounting_accounts
            DROP is_auto_account,
            DROP datev_sachverhalt_l_u_l,
            DROP datev_funktionsergaenzung_l_u_l');

        $this->addSql('ALTER TABLE tax_rates ADD datev_bu_key VARCHAR(4) DEFAULT NULL');
        $this->addSql('UPDATE tax_rates SET datev_bu_key = datev_output_bu_key WHERE datev_output_bu_key IS NOT NULL');
        $this->addSql('ALTER TABLE tax_rates DROP datev_output_bu_key, DROP datev_input_bu_key');
    }
}
