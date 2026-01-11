<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260104090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add monthly statistics snapshot table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE monthly_stats_snapshots (id INT AUTO_INCREMENT NOT NULL, subsidiary_id INT DEFAULT NULL, month SMALLINT NOT NULL, year SMALLINT NOT NULL, is_all TINYINT(1) NOT NULL, metrics JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_month_year (year, month), INDEX IDX_snapshot_subsidiary (subsidiary_id), UNIQUE INDEX uniq_month_year_subsidiary (year, month, is_all, subsidiary_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE monthly_stats_snapshots ADD CONSTRAINT FK_snapshot_subsidiary FOREIGN KEY (subsidiary_id) REFERENCES objects (id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP TABLE monthly_stats_snapshots
        SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
