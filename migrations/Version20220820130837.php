<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220820130837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customers ADD idnumber VARCHAR(255) DEFAULT NULL, DROP address, DROP zip, DROP city, DROP country, DROP phone, DROP fax, DROP mobile_phone, DROP email, CHANGE company id_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE registration_book ADD id_type VARCHAR(255) DEFAULT NULL, ADD idnumber VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE customers ADD company VARCHAR(255) DEFAULT NULL, ADD address VARCHAR(150) DEFAULT NULL, ADD zip VARCHAR(10) DEFAULT NULL, ADD city VARCHAR(45) DEFAULT NULL, ADD country VARCHAR(45) DEFAULT NULL, ADD phone VARCHAR(45) DEFAULT NULL, ADD fax VARCHAR(45) DEFAULT NULL, ADD mobile_phone VARCHAR(45) DEFAULT NULL, ADD email VARCHAR(100) DEFAULT NULL, DROP id_type, DROP idnumber');
        $this->addSql('ALTER TABLE registration_book DROP id_type, DROP idnumber');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
