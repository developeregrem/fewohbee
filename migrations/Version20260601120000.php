<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add min_full_payers to room_category (minimum full-fare guests before guest-category price modifiers apply)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room_category ADD min_full_payers INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE room_category DROP min_full_payers');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
