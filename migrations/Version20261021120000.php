<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the online check-in setting for company details on invoices, off by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE guest_check_in_config ADD company_mode VARCHAR(10) DEFAULT 'hidden' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $offered = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM guest_check_in_config WHERE company_mode <> 'hidden'");
        $this->abortIf($offered > 0, 'Set "Company details for the invoice" to "Do not ask" in the online check-in settings before downgrading.');

        $this->addSql('ALTER TABLE guest_check_in_config DROP company_mode');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
