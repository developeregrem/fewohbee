<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260421120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Split tax_rates.datev_bu_key into datev output/input BU keys; add Automatikkonto, DATEV Sachverhalt/Funktionsergänzung L+L and chart_preset scope to accounting_accounts and tax_rates';
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

        // ── chart_preset scope ─────────────────────────────────────
        // Konten/Steuersätze tragen das Preset, aus dem sie stammen. NULL = vom Nutzer
        // angelegt (sichtbar in jedem Preset). Verhindert Kollisionen zwischen SKR03/SKR04
        // (z.B. 1200 = Bank in SKR03, Forderungen in SKR04).
        $this->addSql('ALTER TABLE accounting_accounts
            ADD chart_preset VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_acct_chart_preset ON accounting_accounts (chart_preset)');

        $this->addSql('ALTER TABLE tax_rates
            ADD chart_preset VARCHAR(20) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_tax_chart_preset ON tax_rates (chart_preset)');

        // Backfill: alle System-Konten dem aktuell aktiven Preset zuordnen.
        // Da accountNumber bisher unique war, kann es maximal eine Variante eines Kontos in der DB geben –
        // diese muss zwangsläufig zum aktuellen Preset gehören. User-Konten bleiben NULL.
        $this->addSql("UPDATE accounting_accounts a
            INNER JOIN (SELECT chart_preset FROM accounting_settings WHERE chart_preset IS NOT NULL LIMIT 1) s
            SET a.chart_preset = s.chart_preset
            WHERE a.is_system_default = 1");

        // TaxRates erben das Preset vom verlinkten Erlöskonto. Sätze ohne Erlöskonto bleiben NULL.
        $this->addSql('UPDATE tax_rates t
            INNER JOIN accounting_accounts a ON t.revenue_account_id = a.id
            SET t.chart_preset = a.chart_preset
            WHERE a.chart_preset IS NOT NULL');

        // Unique-Constraint umbauen: account_number muss nun pro Preset eindeutig sein.
        // Mehrere user-erstellte Konten mit gleicher Nummer werden zusätzlich per UniqueEntity-
        // Validator auf App-Ebene verhindert.
        $this->addSql('DROP INDEX UNIQ_ACCT_NUMBER ON accounting_accounts');
        $this->addSql('CREATE UNIQUE INDEX uniq_account_per_preset ON accounting_accounts (account_number, chart_preset)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_account_per_preset ON accounting_accounts');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_ACCT_NUMBER ON accounting_accounts (account_number)');
        $this->addSql('DROP INDEX idx_acct_chart_preset ON accounting_accounts');
        $this->addSql('DROP INDEX idx_tax_chart_preset ON tax_rates');

        $this->addSql('ALTER TABLE accounting_accounts
            DROP is_auto_account,
            DROP datev_sachverhalt_l_u_l,
            DROP datev_funktionsergaenzung_l_u_l,
            DROP chart_preset');

        $this->addSql('ALTER TABLE tax_rates ADD datev_bu_key VARCHAR(4) DEFAULT NULL');
        $this->addSql('UPDATE tax_rates SET datev_bu_key = datev_output_bu_key WHERE datev_output_bu_key IS NOT NULL');
        $this->addSql('ALTER TABLE tax_rates DROP datev_output_bu_key, DROP datev_input_bu_key, DROP chart_preset');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
