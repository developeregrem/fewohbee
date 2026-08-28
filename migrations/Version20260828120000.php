<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add OIDC identity binding (issuer, subject, linked_at) to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD oidc_issuer VARCHAR(255) DEFAULT NULL, ADD oidc_subject VARCHAR(255) DEFAULT NULL, ADD oidc_linked_at DATETIME DEFAULT NULL');
        // Existing rows keep NULL in both columns; MySQL allows any number of NULLs
        // in a unique index, so the constraint does not affect current data.
        $this->addSql('CREATE UNIQUE INDEX uniq_users_oidc_identity ON users (oidc_issuer, oidc_subject)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_users_oidc_identity ON users');
        $this->addSql('ALTER TABLE users DROP oidc_issuer, DROP oidc_subject, DROP oidc_linked_at');
    }
}
