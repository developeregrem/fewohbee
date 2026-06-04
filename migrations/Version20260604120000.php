<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allowed embedding origins to online booking configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_booking_config ADD allowed_embedding_origins LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_booking_config DROP allowed_embedding_origins');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
