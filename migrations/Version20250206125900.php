<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250206125900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invoice_settings_data (id INT AUTO_INCREMENT NOT NULL, company_name VARCHAR(255) NOT NULL, tax_number VARCHAR(100) DEFAULT NULL, vat_id VARCHAR(100) DEFAULT NULL, contact_name VARCHAR(100) NOT NULL, contact_department VARCHAR(50) DEFAULT NULL, contact_phone VARCHAR(50) NOT NULL, contact_mail VARCHAR(60) NOT NULL, company_invoice_mail VARCHAR(60) NOT NULL, company_address VARCHAR(100) NOT NULL, company_post_code VARCHAR(10) NOT NULL, company_city VARCHAR(45) NOT NULL, company_country VARCHAR(2) NOT NULL, account_iban VARCHAR(50) NOT NULL, account_name VARCHAR(100) NOT NULL, account_bic VARCHAR(50) DEFAULT NULL, payment_terms LONGTEXT DEFAULT NULL, is_active TINYINT(1) NOT NULL, payment_due_days SMALLINT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoices ADD phone VARCHAR(50) DEFAULT NULL, ADD country VARCHAR(2) DEFAULT NULL, ADD email VARCHAR(60) DEFAULT NULL, ADD buyer_reference VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE invoice_settings_data');
        $this->addSql('ALTER TABLE invoices DROP phone, DROP country, DROP email, DROP buyer_reference');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
