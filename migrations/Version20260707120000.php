<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-invoice: default profile ZUGFeRD (en16931), seller contact fields optional (XRechnung only), add seller legal registration identifier (BT-30)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice_settings_data CHANGE einvoice_profile einvoice_profile VARCHAR(50) DEFAULT 'en16931' NOT NULL");
        $this->addSql('ALTER TABLE invoice_settings_data CHANGE contact_name contact_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data CHANGE contact_phone contact_phone VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data CHANGE contact_mail contact_mail VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data ADD registration_number VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_settings_data DROP registration_number');
        $this->addSql("ALTER TABLE invoice_settings_data CHANGE einvoice_profile einvoice_profile VARCHAR(50) DEFAULT 'xrechnung' NOT NULL");
        $this->addSql("UPDATE invoice_settings_data SET contact_name = '' WHERE contact_name IS NULL");
        $this->addSql("UPDATE invoice_settings_data SET contact_phone = '' WHERE contact_phone IS NULL");
        $this->addSql("UPDATE invoice_settings_data SET contact_mail = '' WHERE contact_mail IS NULL");
        $this->addSql('ALTER TABLE invoice_settings_data CHANGE contact_name contact_name VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data CHANGE contact_phone contact_phone VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE invoice_settings_data CHANGE contact_mail contact_mail VARCHAR(60) NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
