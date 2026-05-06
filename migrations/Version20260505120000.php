<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 1 GuestCategory: introduce GuestCategory entity (with M:N to subsidiaries),
 * add guest_counts/kurtaxe_waived/adult_rule_override columns to reservations,
 * seed default guest categories and backfill existing reservations into the
 * default-adult bucket. Adult status is derived from statistical_group.
 */
final class Version20260505120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 1 guest categories: new tables guest_categories + guest_categories_has_subsidiaries; reservation gets guest_counts/kurtaxe_waived/adult_rule_override; seeds default categories and backfills existing reservations. Adult status is derived from statistical_group.';
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
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
