<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260523194549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_mandatory_online flag to prices for mandatory online-booking extras (e.g. cleaning fee)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prices ADD is_mandatory_online TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prices DROP is_mandatory_online');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
