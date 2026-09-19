<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark origins that charge a payment fee as collecting the payment, now that the fee is charged on what the portal processed';
    }

    public function up(Schema $schema): void
    {
        // The payment fee is now taken on what the portal actually processed
        // (see OriginFeeCalculator), and the column added for that defaults to
        // the property - which for an origin that charges a payment fee would
        // quietly book nothing at all from here on.
        //
        // An origin charging a percentage for processing payments does process
        // them; that is what the fee is. So the ones that have such a fee are
        // marked accordingly, and every other origin keeps the default. The
        // tourist tax is left alone: portals differ on it and the setting is
        // there to be answered per origin.
        $this->addSql("UPDATE reservation_origins SET payment_collection = 'portal' WHERE payment_fee_percent IS NOT NULL AND payment_fee_percent > 0");

        // Reservations booked before this keep NULL and fall back to the origin,
        // which now answers for them. Deliberately not stamped: pinning today's
        // answer onto old bookings is exactly what the column exists to prevent.
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE reservation_origins SET payment_collection = 'property' WHERE payment_fee_percent IS NOT NULL AND payment_fee_percent > 0");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
