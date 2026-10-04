<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reservations keep the price they were booked at, so later changes to the price list no longer
 * reach existing bookings. Open bookings made before this update get their promise from the
 * current price list right before a price is changed for the first time
 * (PricePromiseService::promiseOpenReservations()); pricing needs the application, not SQL.
 */
final class Version20261002150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the promised price on reservations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations ADD price_promise JSON DEFAULT NULL');
    }

    /** Refuses to run while open bookings hold a promise, because the old schema cannot keep it. */
    public function down(Schema $schema): void
    {
        $openPromises = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM reservations r WHERE r.price_promise IS NOT NULL'
            .' AND NOT EXISTS (SELECT 1 FROM reservations_has_invoices ri WHERE ri.reservation_id = r.id)'
        );
        $this->abortIf($openPromises > 0,
            'Bookings without an invoice hold a promised price that the previous version cannot keep; they would be priced from the current price list again. Restore the pre-upgrade backup, or run "UPDATE reservations SET price_promise = NULL" to accept this, before downgrading.');

        $this->addSql('ALTER TABLE reservations DROP price_promise');
    }
}
