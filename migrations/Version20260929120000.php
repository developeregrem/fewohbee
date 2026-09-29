<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payment due date per invoice.
 *
 * Existing invoices get none and keep following the payment period in the invoice
 * settings, exactly as before.
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-invoice payment due date';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoices ADD payment_due_date DATE DEFAULT NULL');
    }

    /**
     * Refused while an invoice has a due date of its own: the older schema has no place
     * for it, so rolling back would silently change the date printed on those invoices.
     */
    public function down(Schema $schema): void
    {
        $withOwnDate = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoices WHERE payment_due_date IS NOT NULL');
        $this->abortIf($withOwnDate > 0, sprintf('%d invoice(s) have their own payment due date; clear it before rolling back.', $withOwnDate));

        $this->addSql('ALTER TABLE invoices DROP payment_due_date');
    }
}
