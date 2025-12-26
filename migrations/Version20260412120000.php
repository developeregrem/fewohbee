<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client label and user agent metadata to WebAuthn credentials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webauthn_credentials ADD client_label VARCHAR(255) DEFAULT NULL, ADD user_agent LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE webauthn_credentials ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->addSql('UPDATE webauthn_credentials SET created_at = NOW() WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE webauthn_credentials ALTER created_at DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE webauthn_credentials DROP client_label, DROP user_agent, DROP created_at');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
