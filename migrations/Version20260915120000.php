<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the complete portal fee accounting model introduced by pull request #293.
 *
 * Fee rates and collection choices are pinned to new reservations so later contract changes do
 * not alter old bookings. Existing reservations stay NULL and fall back to their origin because
 * their historical values were not recorded. Existing tourist-tax positions are marked as
 * collected by the property and not commissionable. An origin with a payment fee is treated as
 * collecting the payment through the portal, otherwise the new default would suppress its fee.
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add portal fee settings, pinned reservation values, calculation flags and document tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_entries ADD requires_document_number TINYINT(1) DEFAULT 0 NOT NULL');

        $this->addSql("ALTER TABLE reservation_origins ADD commission_percent NUMERIC(5, 2) DEFAULT NULL, ADD payment_fee_percent NUMERIC(5, 2) DEFAULT NULL, ADD payment_collection VARCHAR(16) DEFAULT 'property' NOT NULL, ADD tourist_tax_collection VARCHAR(16) DEFAULT 'property' NOT NULL");

        // Historical bookings keep NULL and use their origin because their original values are unknown.
        $this->addSql('ALTER TABLE reservations ADD commission_percent NUMERIC(5, 2) DEFAULT NULL, ADD payment_fee_percent NUMERIC(5, 2) DEFAULT NULL, ADD payment_collection VARCHAR(16) DEFAULT NULL, ADD tourist_tax_collection VARCHAR(16) DEFAULT NULL');

        $this->addSql('ALTER TABLE invoice_positions ADD brokered TINYINT(1) DEFAULT 1 NOT NULL, ADD commissionable TINYINT(1) DEFAULT 1 NOT NULL');
        // Separately billed tourist tax is neither brokered nor commissionable for existing invoices.
        $this->addSql("UPDATE invoice_positions SET brokered = 0, commissionable = 0 WHERE position_group = 'tourist_tax'");

        $this->addSql('ALTER TABLE prices ADD brokered TINYINT(1) DEFAULT 1 NOT NULL');

        // A positive processing fee means the portal collected the payment it charged for.
        $this->addSql("UPDATE reservation_origins SET payment_collection = 'portal' WHERE payment_fee_percent IS NOT NULL AND payment_fee_percent > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE prices DROP brokered');
        $this->addSql('ALTER TABLE invoice_positions DROP brokered, DROP commissionable');
        $this->addSql('ALTER TABLE reservations DROP commission_percent, DROP payment_fee_percent, DROP payment_collection, DROP tourist_tax_collection');
        $this->addSql('ALTER TABLE reservation_origins DROP commission_percent, DROP payment_fee_percent, DROP payment_collection, DROP tourist_tax_collection');
        $this->addSql('ALTER TABLE booking_entries DROP requires_document_number');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
