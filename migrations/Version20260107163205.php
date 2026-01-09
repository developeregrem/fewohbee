<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260107163205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add theme preference to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD theme_preference VARCHAR(10) NOT NULL DEFAULT 'light'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP theme_preference');
    }    
    
    public function isTransactional(): bool
    {
        return true;
    }
}
