<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804102205 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_tokens table for personal access tokens (REST API)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_tokens (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, token_prefix VARCHAR(12) NOT NULL, token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', user_id INT NOT NULL, UNIQUE INDEX UNIQ_2CAD560EB3BC57DA (token_hash), INDEX IDX_2CAD560EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_2CAD560EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_2CAD560EA76ED395');
        $this->addSql('DROP TABLE api_tokens');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
