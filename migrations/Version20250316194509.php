<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250316194509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoices ADD payment_means INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD card_number VARCHAR(19) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data ADD creditor_reference VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD customer_iban VARCHAR(50) DEFAULT NULL, ADD card_holder VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD mandate_reference VARCHAR(50) DEFAULT NULL');
        
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoices DROP payment_means');
        $this->addSql('ALTER TABLE invoices DROP card_number');
        $this->addSql('ALTER TABLE invoice_settings_data DROP creditor_reference');
        $this->addSql('ALTER TABLE invoices DROP customer_iban, DROP card_holder');
        $this->addSql('ALTER TABLE invoices DROP mandate_reference');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
