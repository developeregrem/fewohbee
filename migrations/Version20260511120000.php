<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payment module: introduce payment_transactions table for the provider-agnostic
 * payment integration (first concrete adapter: Payactive).
 */
final class Version20260511120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'payment module: payment_transactions table for provider-agnostic payment integration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_transactions (
            id                    INT AUTO_INCREMENT NOT NULL,
            provider_id           VARCHAR(50) NOT NULL,
            provider_payment_id   VARCHAR(191) NOT NULL,
            external_reference    VARCHAR(191) NOT NULL,
            amount                NUMERIC(10, 2) NOT NULL,
            currency              VARCHAR(3) NOT NULL,
            status                VARCHAR(20) NOT NULL,
            intent                VARCHAR(20) NOT NULL,
            purpose               VARCHAR(255) NOT NULL,
            metadata              JSON DEFAULT NULL,
            created_at            DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at            DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_pt_provider_payment (provider_id, provider_payment_id),
            INDEX idx_pt_external_reference (external_reference),
            INDEX idx_pt_status (status),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_transactions');
    }
}
