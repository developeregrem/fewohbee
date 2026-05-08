<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * GuestCategory: introduce GuestCategory entity (with M:N to subsidiaries),
 * add guest_counts/kurtaxe_waived/adult_rule_override columns to reservations,
 * seed default guest categories and backfill existing reservations into the
 * default-adult bucket. Adult status is derived from statistical_group.
 */
final class Version20260505120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guest categories: new tables guest_categories + guest_categories_has_subsidiaries; reservation gets guest_counts/kurtaxe_waived/adult_rule_override; seeds default categories and backfills existing reservations. Adult status is derived from statistical_group.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guest_categories (
            id                      INT AUTO_INCREMENT NOT NULL,
            name                    VARCHAR(100) NOT NULL,
            acronym                 VARCHAR(20) NOT NULL,
            min_age                 SMALLINT DEFAULT NULL,
            max_age                 SMALLINT DEFAULT NULL,
            is_counted_in_occupancy TINYINT(1) NOT NULL DEFAULT 1,
            statistical_group       VARCHAR(20) NOT NULL,
            sort_order              INT NOT NULL DEFAULT 0,
            active                  TINYINT(1) NOT NULL DEFAULT 1,
            system_code             VARCHAR(50) DEFAULT NULL,
            UNIQUE INDEX UNIQ_guest_category_system_code (system_code),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE guest_categories_has_subsidiaries (
            guest_category_id INT NOT NULL,
            subsidiary_id     INT NOT NULL,
            INDEX IDX_gchsub_category (guest_category_id),
            INDEX IDX_gchsub_subsidiary (subsidiary_id),
            PRIMARY KEY (guest_category_id, subsidiary_id),
            CONSTRAINT FK_gchsub_category FOREIGN KEY (guest_category_id) REFERENCES guest_categories (id) ON DELETE CASCADE,
            CONSTRAINT FK_gchsub_subsidiary FOREIGN KEY (subsidiary_id) REFERENCES objects (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('ALTER TABLE reservations
            ADD guest_counts JSON NOT NULL,
            ADD kurtaxe_waived TINYINT(1) NOT NULL DEFAULT 0,
            ADD adult_rule_override TINYINT(1) NOT NULL DEFAULT 0');

        // Seed default guest categories with literal German default values
        // (anwender-editierbare Stammdaten, keine Translation-Keys).
        $this->addSql("INSERT INTO guest_categories
            (name, acronym, min_age, max_age, is_counted_in_occupancy, statistical_group, sort_order, active, system_code)
            VALUES
            ('Erwachsene',              'ERW',   18,   NULL, 1, 'adult',  10, 1, 'default_adult'),
            ('Kind 6-17',               'K6-17', 6,    17,   1, 'child',  20, 1, 'default_child'),
            ('Kleinkind 0-5',           'BABY',  0,    5,    0, 'infant', 30, 1, 'default_infant'),
            ('Nichtpflichtige Personen','NP',    NULL, NULL, 1, 'other',  40, 1, 'default_exempt')");

        // Backfill existing reservations into the default-adult bucket so
        // guest_counts is the authoritative source from now on.
        $this->addSql("UPDATE reservations r
            JOIN guest_categories gc ON gc.system_code = 'default_adult'
            SET r.guest_counts = JSON_OBJECT(CAST(gc.id AS CHAR), r.persons)
            WHERE r.persons IS NOT NULL AND r.persons > 0");

        $this->addSql("UPDATE reservations
            SET guest_counts = JSON_OBJECT()
            WHERE guest_counts IS NULL OR JSON_LENGTH(guest_counts) IS NULL");

        // tourist tax: tourist_taxes, tourist_taxes_has_subsidiaries, tourist_tax_rates.
        $this->addSql('CREATE TABLE tourist_taxes (
            id                     INT AUTO_INCREMENT NOT NULL,
            tax_rate_id            INT DEFAULT NULL,
            revenue_account_id     INT DEFAULT NULL,
            name                   VARCHAR(100) NOT NULL,
            valid_from             DATE DEFAULT NULL,
            valid_to               DATE DEFAULT NULL,
            includes_vat           TINYINT(1) NOT NULL DEFAULT 1,
            active                 TINYINT(1) NOT NULL DEFAULT 1,
            applies_only_to_adult  TINYINT(1) NOT NULL DEFAULT 0,
            sort_order             INT NOT NULL DEFAULT 0,
            INDEX IDX_tt_taxrate (tax_rate_id),
            INDEX IDX_tt_revenue (revenue_account_id),
            CONSTRAINT FK_tt_taxrate FOREIGN KEY (tax_rate_id) REFERENCES tax_rates (id) ON DELETE SET NULL,
            CONSTRAINT FK_tt_revenue FOREIGN KEY (revenue_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE tourist_taxes_has_subsidiaries (
            tourist_tax_id INT NOT NULL,
            subsidiary_id  INT NOT NULL,
            INDEX IDX_tths_tax (tourist_tax_id),
            INDEX IDX_tths_subsidiary (subsidiary_id),
            PRIMARY KEY (tourist_tax_id, subsidiary_id),
            CONSTRAINT FK_tths_tax FOREIGN KEY (tourist_tax_id) REFERENCES tourist_taxes (id) ON DELETE CASCADE,
            CONSTRAINT FK_tths_subsidiary FOREIGN KEY (subsidiary_id) REFERENCES objects (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        $this->addSql('CREATE TABLE tourist_tax_rates (
            id                INT AUTO_INCREMENT NOT NULL,
            tourist_tax_id    INT NOT NULL,
            guest_category_id INT NOT NULL,
            price_per_night   NUMERIC(10, 2) NOT NULL,
            report_group      VARCHAR(50) DEFAULT NULL,
            INDEX IDX_ttr_tax (tourist_tax_id),
            INDEX IDX_ttr_category (guest_category_id),
            UNIQUE INDEX UNIQ_ttr_tax_category (tourist_tax_id, guest_category_id),
            CONSTRAINT FK_ttr_tax FOREIGN KEY (tourist_tax_id) REFERENCES tourist_taxes (id) ON DELETE CASCADE,
            CONSTRAINT FK_ttr_category FOREIGN KEY (guest_category_id) REFERENCES guest_categories (id) ON DELETE CASCADE,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');

        // invoice integration: invoice_positions.position_group marker
        $this->addSql('ALTER TABLE invoice_positions ADD position_group VARCHAR(32) DEFAULT NULL');

        // pricing modifier layer: guest_category_modifiers (subsidiary scope inherited from category)
        $this->addSql('CREATE TABLE guest_category_modifiers (
            id          INT AUTO_INCREMENT NOT NULL,
            category_id INT NOT NULL,
            type        VARCHAR(32) NOT NULL,
            value       NUMERIC(10, 2) NOT NULL,
            valid_from  DATE DEFAULT NULL,
            valid_to    DATE DEFAULT NULL,
            active      TINYINT(1) NOT NULL DEFAULT 1,
            sort_order  INT NOT NULL DEFAULT 0,
            INDEX IDX_gcm_category (category_id),
            CONSTRAINT FK_gcm_category FOREIGN KEY (category_id) REFERENCES guest_categories (id) ON DELETE CASCADE,
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations
            DROP COLUMN guest_counts,
            DROP COLUMN kurtaxe_waived,
            DROP COLUMN adult_rule_override');
        $this->addSql('ALTER TABLE guest_categories_has_subsidiaries DROP FOREIGN KEY FK_gchsub_category');
        $this->addSql('ALTER TABLE guest_categories_has_subsidiaries DROP FOREIGN KEY FK_gchsub_subsidiary');
        $this->addSql('DROP TABLE guest_categories_has_subsidiaries');
        $this->addSql('DROP TABLE guest_categories');

        $this->addSql('ALTER TABLE tourist_tax_rates DROP FOREIGN KEY FK_ttr_tax');
        $this->addSql('ALTER TABLE tourist_tax_rates DROP FOREIGN KEY FK_ttr_category');
        $this->addSql('DROP TABLE tourist_tax_rates');
        $this->addSql('ALTER TABLE tourist_taxes_has_subsidiaries DROP FOREIGN KEY FK_tths_tax');
        $this->addSql('ALTER TABLE tourist_taxes_has_subsidiaries DROP FOREIGN KEY FK_tths_subsidiary');
        $this->addSql('DROP TABLE tourist_taxes_has_subsidiaries');
        $this->addSql('ALTER TABLE tourist_taxes DROP FOREIGN KEY FK_tt_taxrate');
        $this->addSql('ALTER TABLE tourist_taxes DROP FOREIGN KEY FK_tt_revenue');
        $this->addSql('DROP TABLE tourist_taxes');

        $this->addSql('ALTER TABLE invoice_positions DROP COLUMN position_group');

        $this->addSql('ALTER TABLE guest_category_modifiers DROP FOREIGN KEY FK_gcm_category');
        $this->addSql('DROP TABLE guest_category_modifiers');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
