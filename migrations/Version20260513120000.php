<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260513120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rebuild logging table to match the redesigned Log entity (auto-id PK, nullable user FK, datetime, entity reference, JSON changes, IP).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS logging');

        $this->addSql('CREATE TABLE logging (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(180) DEFAULT NULL,
            date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            entity_class VARCHAR(255) NOT NULL,
            entity_id VARCHAR(64) DEFAULT NULL,
            action VARCHAR(16) NOT NULL,
            changes JSON DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            INDEX idx_logging_date (date),
            INDEX idx_logging_entity (entity_class, entity_id),
            INDEX idx_logging_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE logging
            ADD CONSTRAINT FK_logging_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE logging DROP FOREIGN KEY FK_logging_user');
        $this->addSql('DROP TABLE IF EXISTS logging');

        $this->addSql('CREATE TABLE logging (
            user_id INT NOT NULL,
            date TIME NOT NULL,
            action VARCHAR(255) NOT NULL,
            PRIMARY KEY(user_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
