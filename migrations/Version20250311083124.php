<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250311083124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE roles CHANGE role role VARCHAR(30) NOT NULL');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "ReadOnly", "ROLE_RESERVATIONS_RO")');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roles CHANGE role role VARCHAR(20)');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_RESERVATIONS_RO"');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
