<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new/updated/unchanged breakdown columns to calendar last-sync bookkeeping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar ADD last_sync_new_count INT DEFAULT NULL, ADD last_sync_updated_count INT DEFAULT NULL, ADD last_sync_unchanged_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar DROP last_sync_new_count, DROP last_sync_updated_count, DROP last_sync_unchanged_count');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
