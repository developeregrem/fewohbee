<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A contact address can carry a separate email for invoices, e.g. the guest's accounting
 * department, while confirmations keep going to the regular email.
 */
final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a separate invoice email to customer addresses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses ADD invoice_email VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_addresses DROP invoice_email');
    }
}
