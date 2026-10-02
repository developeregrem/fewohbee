<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payment due date per invoice.
 *
 * Existing invoices get the date they have been printing so far: invoice date plus the
 * payment period of their issuer - the settings row of their branch, else the active
 * one, as EInvoiceReadinessService::resolveSettingsFor() resolves it. From then on the
 * date is kept, so changing the period in the settings no longer rewrites invoices
 * already sent. Invoices whose issuer states no period stay without a date.
 */
final class Version20260929120000 extends AbstractMigration
{
    /** The period the app resolves for an invoice `i`, given the joined `sub` and `act` rows. */
    private const PERIOD = 'CASE WHEN sub.id IS NOT NULL THEN sub.payment_due_days ELSE act.payment_due_days END';

    /** The settings rows an invoice `i` resolves to; findActive() takes the first active row. */
    private const SETTINGS_JOINS = 'LEFT JOIN invoice_settings_data sub ON sub.subsidiary_id = i.subsidiary_id
        LEFT JOIN (SELECT payment_due_days FROM invoice_settings_data WHERE is_active = 1 ORDER BY id LIMIT 1) act ON 1 = 1';

    public function getDescription(): string
    {
        return 'Add the per-invoice payment due date and fix it for existing invoices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoices ADD payment_due_date DATE DEFAULT NULL');
        $this->addSql('UPDATE invoices i '.self::SETTINGS_JOINS.'
            SET i.payment_due_date = DATE_ADD(i.date, INTERVAL '.self::PERIOD.' DAY)
            WHERE i.payment_due_date IS NULL AND '.self::PERIOD.' IS NOT NULL');
    }

    /**
     * Refused while an invoice has a due date other than the one its settings give: the
     * older schema can only derive the date, so rolling back would silently change what
     * those invoices print.
     */
    public function down(Schema $schema): void
    {
        $deviating = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoices i '.self::SETTINGS_JOINS.'
            WHERE i.payment_due_date IS NOT NULL
              AND (('.self::PERIOD.') IS NULL OR i.payment_due_date <> DATE_ADD(i.date, INTERVAL '.self::PERIOD.' DAY))');
        $this->abortIf($deviating > 0, sprintf('%d invoice(s) have a due date other than their settings give; align them before rolling back.', $deviating));

        $this->addSql('ALTER TABLE invoices DROP payment_due_date');
    }
}
