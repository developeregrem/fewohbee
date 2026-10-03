<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Price rules: percentage changes of the room price by weekday, date range, lead time or
 * occupancy, for one, several or all subsidiaries and room categories, within global limits.
 */
final class Version20261003120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add price rules and the limits for price changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_rules (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, enabled TINYINT NOT NULL, rule_condition VARCHAR(20) NOT NULL, percent NUMERIC(5, 2) NOT NULL, days SMALLINT DEFAULT NULL, occupancy SMALLINT DEFAULT NULL, occupancy_across_subsidiaries TINYINT DEFAULT 0 NOT NULL, weekdays JSON NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, all_subsidiaries TINYINT NOT NULL, all_categories TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE price_rule_subsidiary (rule_id INT NOT NULL, subsidiary_id INT NOT NULL, INDEX IDX_8AF1E2A3744E0351 (rule_id), INDEX IDX_8AF1E2A3D4A7BDA2 (subsidiary_id), PRIMARY KEY (rule_id, subsidiary_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE price_rule_category (rule_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_A94245D8744E0351 (rule_id), INDEX IDX_A94245D812469DE2 (category_id), PRIMARY KEY (rule_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE price_rule_subsidiary ADD CONSTRAINT FK_8AF1E2A3744E0351 FOREIGN KEY (rule_id) REFERENCES price_rules (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_rule_subsidiary ADD CONSTRAINT FK_8AF1E2A3D4A7BDA2 FOREIGN KEY (subsidiary_id) REFERENCES objects (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_rule_category ADD CONSTRAINT FK_A94245D8744E0351 FOREIGN KEY (rule_id) REFERENCES price_rules (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_rule_category ADD CONSTRAINT FK_A94245D812469DE2 FOREIGN KEY (category_id) REFERENCES room_category (id) ON DELETE CASCADE');
        $this->addSql("ALTER TABLE app_settings ADD price_change_min_percent SMALLINT DEFAULT -30 NOT NULL, ADD price_change_max_percent SMALLINT DEFAULT 50 NOT NULL, ADD price_change_rounding VARCHAR(10) DEFAULT 'euro' NOT NULL");
    }

    /** Refuses to run while price rules exist, because the old schema cannot keep them. */
    public function down(Schema $schema): void
    {
        $rules = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM price_rules');
        $this->abortIf($rules > 0, 'Price rules exist and would be lost. Delete them in the price settings, or restore the pre-upgrade backup, before downgrading.');

        $this->addSql('ALTER TABLE price_rule_subsidiary DROP FOREIGN KEY FK_8AF1E2A3744E0351');
        $this->addSql('ALTER TABLE price_rule_subsidiary DROP FOREIGN KEY FK_8AF1E2A3D4A7BDA2');
        $this->addSql('ALTER TABLE price_rule_category DROP FOREIGN KEY FK_A94245D8744E0351');
        $this->addSql('ALTER TABLE price_rule_category DROP FOREIGN KEY FK_A94245D812469DE2');
        $this->addSql('DROP TABLE price_rule_subsidiary');
        $this->addSql('DROP TABLE price_rule_category');
        $this->addSql('DROP TABLE price_rules');
        $this->addSql('ALTER TABLE app_settings DROP price_change_min_percent, DROP price_change_max_percent, DROP price_change_rounding');
    }
}
