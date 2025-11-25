<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250407071506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE webauthn_credentials (id VARCHAR(255) NOT NULL, public_key_credential_id LONGTEXT NOT NULL, type VARCHAR(255) NOT NULL, transports JSON NOT NULL COMMENT '(DC2Type:json)', attestation_type VARCHAR(255) NOT NULL, trust_path JSON NOT NULL COMMENT '(DC2Type:trust_path)', aaguid TINYTEXT NOT NULL, credential_public_key LONGTEXT NOT NULL, user_handle VARCHAR(255) NOT NULL, counter INT NOT NULL, other_ui JSON DEFAULT NULL COMMENT '(DC2Type:json)', backup_eligible TINYINT(1) DEFAULT NULL, backup_status TINYINT(1) DEFAULT NULL, uv_initialized TINYINT(1) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP TABLE webauthn_credentials
        SQL);
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
