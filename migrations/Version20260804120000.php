<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record on the data what a portal charges its fees on: brokered/commissionable per invoice position, brokered per price, and who collects the payment on origin and reservation';
    }

    public function up(Schema $schema): void
    {
        // What a portal brokered, and what it charges commission on, becomes a
        // property of the position rather than a setting in a workflow: the
        // invoice's own figures and the journal's deduction both read it, and
        // neither can reach a workflow's config.
        $this->addSql('ALTER TABLE invoice_positions ADD brokered TINYINT(1) DEFAULT 1 NOT NULL, ADD commissionable TINYINT(1) DEFAULT 1 NOT NULL');

        // A tourist tax billed as its own position carries no commission, which
        // existing invoices are set to. It rests on the tax being set up
        // separately at the portal - see InvoicePosition::$commissionable.
        $this->addSql("UPDATE invoice_positions SET commissionable = 0 WHERE position_group = 'tourist_tax'");

        // Whether the portal also collected that tax cannot be told from an
        // invoice already written, so it is assumed to have been paid at the
        // property - the common case, and the one that charges no payment fee on
        // it. Going forward the origin's tourist_tax_collection decides.
        $this->addSql("UPDATE invoice_positions SET brokered = 0 WHERE position_group = 'tourist_tax'");

        // The default for the positions made from a price. True for everything
        // booked along with the stay; false is for what the house sells on site.
        $this->addSql('ALTER TABLE prices ADD brokered TINYINT(1) DEFAULT 1 NOT NULL');

        // Who takes the money. Defaults to the property on both counts, which is
        // what a direct booking does and what an origin that merely passes
        // bookings on does - no payment handled, no payment fee charged. A portal
        // that settles payments itself is configured as such once, per origin.
        $this->addSql("ALTER TABLE reservation_origins ADD payment_collection VARCHAR(16) DEFAULT 'property' NOT NULL, ADD tourist_tax_collection VARCHAR(16) DEFAULT 'property' NOT NULL");

        // Pinned per reservation like the two rates, and left null here for the
        // same reason they were: nothing was recorded for bookings that predate
        // the column, and null falls back to the origin rather than asserting an
        // answer nobody gave.
        $this->addSql('ALTER TABLE reservations ADD payment_collection VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_positions DROP brokered, DROP commissionable');
        $this->addSql('ALTER TABLE prices DROP brokered');
        $this->addSql('ALTER TABLE reservation_origins DROP payment_collection, DROP tourist_tax_collection');
        $this->addSql('ALTER TABLE reservations DROP payment_collection');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
