<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoice templates can be bound to a payment means, so e.g. cash invoices are printed with
 * their own template without switching the selection by hand.
 */
final class Version20260927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the payment means an invoice template is used for';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE templates ADD payment_means INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE templates DROP payment_means');
    }
}
