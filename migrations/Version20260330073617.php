<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330073617 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add buyer VAT ID, buyer reference and customer IBAN to customer_addresses and buyer VAT ID to invoices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses ADD buyer_vat_id VARCHAR(50) DEFAULT NULL, ADD buyer_reference VARCHAR(100) DEFAULT NULL, ADD customer_iban VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoices ADD buyer_vat_id VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses DROP buyer_vat_id, DROP buyer_reference, DROP customer_iban');
        $this->addSql('ALTER TABLE invoices DROP buyer_vat_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
