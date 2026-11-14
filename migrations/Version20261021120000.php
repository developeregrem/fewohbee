<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoice details from guests.
 *
 * - customer_addresses.invoice_email: a contact address can carry a separate email for invoices,
 *   e.g. the guest's accounting department, while confirmations keep going to the regular email;
 * - guest_check_in_config.company_mode: the online check-in can ask for company details for the
 *   invoice, off by default.
 */
final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a separate invoice email to customer addresses and the online check-in setting for company details on invoices, off by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses ADD invoice_email VARCHAR(100) DEFAULT NULL');
        $this->addSql("ALTER TABLE guest_check_in_config ADD company_mode VARCHAR(10) DEFAULT 'hidden' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $offered = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM guest_check_in_config WHERE company_mode <> 'hidden'");
        $this->abortIf($offered > 0, 'Set "Company details for the invoice" to "Do not ask" in the online check-in settings before downgrading.');

        $this->addSql('ALTER TABLE guest_check_in_config DROP company_mode');
        $this->addSql('ALTER TABLE customer_addresses DROP invoice_email');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
