<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TouristTax: introduce calculation modes for City Tax / Beherbergungssteuer.
 * Existing per-night-flat tourist taxes are preserved via the default value.
 */
final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'tourist_taxes: add calculation_mode, percentage_rate, percentage_base for percent-based city taxes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tourist_taxes
            ADD calculation_mode VARCHAR(32) NOT NULL DEFAULT 'per_night_flat',
            ADD percentage_rate  NUMERIC(5, 2) DEFAULT NULL,
            ADD percentage_base  VARCHAR(16) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tourist_taxes
            DROP COLUMN calculation_mode,
            DROP COLUMN percentage_rate,
            DROP COLUMN percentage_base');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
