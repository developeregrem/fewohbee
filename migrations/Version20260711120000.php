<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add active status to rooms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appartments ADD active TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appartments DROP active');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
