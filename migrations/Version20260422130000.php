<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add price_components for split-VAT packages, optional revenue_account overrides on prices, price_components, invoice_positions and invoice_appartments, plus accounting_settings labels for booking journal remarks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_components (
            id INT AUTO_INCREMENT NOT NULL,
            price_id INT NOT NULL,
            revenue_account_id INT DEFAULT NULL,
            description VARCHAR(100) NOT NULL,
            vat NUMERIC(10, 2) NOT NULL,
            allocation_type VARCHAR(16) NOT NULL,
            allocation_value NUMERIC(10, 4) NOT NULL,
            is_remainder TINYINT(1) NOT NULL,
            sort_order SMALLINT NOT NULL,
            INDEX IDX_PRICE_COMPONENTS_PRICE (price_id),
            INDEX IDX_PRICE_COMPONENTS_REVENUE_ACCOUNT (revenue_account_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE price_components
            ADD CONSTRAINT FK_PRICE_COMPONENTS_PRICE FOREIGN KEY (price_id) REFERENCES prices (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE price_components
            ADD CONSTRAINT FK_PRICE_COMPONENTS_REVENUE_ACCOUNT FOREIGN KEY (revenue_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE prices ADD revenue_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prices
            ADD CONSTRAINT FK_PRICES_REVENUE_ACCOUNT FOREIGN KEY (revenue_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PRICES_REVENUE_ACCOUNT ON prices (revenue_account_id)');

        $this->addSql('ALTER TABLE invoice_positions ADD revenue_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_positions
            ADD CONSTRAINT FK_INVOICE_POSITIONS_REVENUE_ACCOUNT FOREIGN KEY (revenue_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_INVOICE_POSITIONS_REVENUE_ACCOUNT ON invoice_positions (revenue_account_id)');

        $this->addSql('ALTER TABLE invoice_appartments ADD revenue_account_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_appartments
            ADD CONSTRAINT FK_INVOICE_APPARTMENTS_REVENUE_ACCOUNT FOREIGN KEY (revenue_account_id) REFERENCES accounting_accounts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_INVOICE_APPARTMENTS_REVENUE_ACCOUNT ON invoice_appartments (revenue_account_id)');

        $this->addSql('ALTER TABLE accounting_settings
            ADD main_position_label VARCHAR(60) DEFAULT NULL,
            ADD misc_position_label VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounting_settings
            DROP main_position_label,
            DROP misc_position_label');

        $this->addSql('ALTER TABLE invoice_appartments DROP FOREIGN KEY FK_INVOICE_APPARTMENTS_REVENUE_ACCOUNT');
        $this->addSql('DROP INDEX IDX_INVOICE_APPARTMENTS_REVENUE_ACCOUNT ON invoice_appartments');
        $this->addSql('ALTER TABLE invoice_appartments DROP revenue_account_id');

        $this->addSql('ALTER TABLE invoice_positions DROP FOREIGN KEY FK_INVOICE_POSITIONS_REVENUE_ACCOUNT');
        $this->addSql('DROP INDEX IDX_INVOICE_POSITIONS_REVENUE_ACCOUNT ON invoice_positions');
        $this->addSql('ALTER TABLE invoice_positions DROP revenue_account_id');

        $this->addSql('ALTER TABLE prices DROP FOREIGN KEY FK_PRICES_REVENUE_ACCOUNT');
        $this->addSql('DROP INDEX IDX_PRICES_REVENUE_ACCOUNT ON prices');
        $this->addSql('ALTER TABLE prices DROP revenue_account_id');

        $this->addSql('ALTER TABLE price_components DROP FOREIGN KEY FK_PRICE_COMPONENTS_REVENUE_ACCOUNT');
        $this->addSql('ALTER TABLE price_components DROP FOREIGN KEY FK_PRICE_COMPONENTS_PRICE');
        $this->addSql('DROP TABLE price_components');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
