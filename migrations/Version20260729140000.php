<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional start time to calendar entries (null keeps an entry all-day)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry ADD time TIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_entry DROP time');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
