<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251121120640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "Reservations", "ROLE_RESERVATIONS")');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "Invoices", "ROLE_INVOICES")');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "Customers", "ROLE_CUSTOMERS")');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "RegistrationBook", "ROLE_REGISTRATIONBOOK")');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "Statistics", "ROLE_STATISTICS")');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "Cashjournal", "ROLE_CASHJOURNAL")');

        $this->addSql('CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_54FCD59FA76ED395 (user_id), INDEX IDX_54FCD59FD60322AC (role_id), PRIMARY KEY(user_id, role_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');

        // keep ROLE_ADMIN and ROLE_RESERVATIONS_RO assignments
        $this->addSql('INSERT INTO user_roles (user_id, role_id)
            SELECT u.id, u.role_id FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE r.role IN ("ROLE_ADMIN", "ROLE_RESERVATIONS_RO")');

        // users with ROLE_USER get all new feature roles
        $featureRoles = [
            'ROLE_RESERVATIONS',
            'ROLE_CUSTOMERS',
            'ROLE_INVOICES',
            'ROLE_REGISTRATIONBOOK',
            'ROLE_STATISTICS',
            'ROLE_CASHJOURNAL',
        ];
        foreach ($featureRoles as $featureRole) {
            $this->addSql(sprintf(
                'INSERT INTO user_roles (user_id, role_id)
                    SELECT u.id, fr.id
                    FROM users u
                    JOIN roles oldRole ON oldRole.id = u.role_id AND oldRole.role = "ROLE_USER"
                    JOIN roles fr ON fr.role = "%s"',
                $featureRole
            ));
        }

        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9D60322AC');
        $this->addSql('DROP INDEX IDX_1483A5E9D60322AC ON users');
        $this->addSql('ALTER TABLE users DROP role_id');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_USER"');
        
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FD60322AC');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('ALTER TABLE users ADD role_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9D60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');
        $this->addSql('CREATE INDEX IDX_1483A5E9D60322AC ON users (role_id)');
        
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_RESERVATIONS"');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_INVOICES"');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_CUSTOMERS"');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_REGISTRATIONBOOK"');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_STATISTICS"');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_CASHJOURNAL"');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_USER"');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "User", "ROLE_USER")');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
