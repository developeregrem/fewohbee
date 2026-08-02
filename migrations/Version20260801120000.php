<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a color to reservation origins for the reservation overview accent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_origins ADD color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_origins DROP color');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
