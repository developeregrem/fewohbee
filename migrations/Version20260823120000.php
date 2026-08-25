<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * In-app release notes: remember which version each user has already been shown.
 *
 * `last_seen_version` stays NULL on purpose for existing users. NULL means "never
 * announced", so everybody sees the notes for the version that introduces this
 * feature once, and the announcement is only marked as seen when the user
 * actually dismisses the modal.
 *
 * Fresh installations do not need the announcement — FirstRunCommand stamps the
 * current version on the initial administrator instead.
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.last_seen_version for the in-app release notes announcement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_seen_version VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP last_seen_version');
    }

    public function isTransactional(): bool
    {
        // DDL and DML in one migration; MySQL cannot roll DDL back anyway.
        return false;
    }
}
