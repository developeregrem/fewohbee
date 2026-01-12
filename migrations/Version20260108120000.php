<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add use_company_name to reservation origins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_origins ADD use_company_name TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_origins DROP use_company_name');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
