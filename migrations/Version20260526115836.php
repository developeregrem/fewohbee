<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526115836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

public function up(Schema $schema): void
{
    $this->addSql('ALTER TABLE templates ADD hidden TINYINT(1) NOT NULL DEFAULT 0');
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE templates DROP COLUMN hidden');
}
}
