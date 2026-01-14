<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add e-invoice profile selection to invoice settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice_settings_data ADD einvoice_profile VARCHAR(50) NOT NULL DEFAULT 'xrechnung'");
        $this->addSql("UPDATE invoice_settings_data SET einvoice_profile = 'xrechnung' WHERE einvoice_profile IS NULL OR einvoice_profile = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_settings_data DROP einvoice_profile');
    }
}
