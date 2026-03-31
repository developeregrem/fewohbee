<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260412120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add buyer VAT ID, buyer reference and customer IBAN to customer_addresses and buyer VAT ID to invoices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses ADD buyer_vat_id VARCHAR(50) DEFAULT NULL, ADD buyer_reference VARCHAR(100) DEFAULT NULL, ADD customer_iban VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD buyer_vat_id VARCHAR(50) DEFAULT NULL');

        $this->addSql('ALTER TABLE webauthn_credentials CHANGE public_key_credential_id public_key_credential_id TINYTEXT NOT NULL, CHANGE transports transports JSON NOT NULL, CHANGE trust_path trust_path JSON NOT NULL, CHANGE other_ui other_ui JSON DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DFEA849072A8BD77 ON webauthn_credentials (public_key_credential_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses DROP buyer_vat_id, DROP buyer_reference, DROP customer_iban');
        $this->addSql('ALTER TABLE invoices DROP buyer_vat_id');

        $this->addSql('DROP INDEX UNIQ_DFEA849072A8BD77 ON webauthn_credentials');
        $this->addSql('ALTER TABLE webauthn_credentials CHANGE public_key_credential_id public_key_credential_id LONGTEXT NOT NULL, CHANGE transports transports JSON NOT NULL COMMENT \'(DC2Type:json)\', CHANGE trust_path trust_path JSON NOT NULL COMMENT \'(DC2Type:trust_path)\', CHANGE other_ui other_ui JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
