<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pin who collects the tourist tax onto the reservation, as the payment collection and the rates already are';
    }

    public function up(Schema $schema): void
    {
        // The tourist tax was the one answer still read live off the origin
        // while the invoice was written. An origin that changes who collects it
        // would therefore have changed what older, not yet invoiced bookings
        // are charged - the very thing the pinned columns next to this one
        // exist to prevent.
        $this->addSql('ALTER TABLE reservations ADD tourist_tax_collection VARCHAR(16) DEFAULT NULL');

        // Left NULL for everything booked so far, which falls back to the
        // origin. Stamping today's answer onto old bookings would assert
        // something about them that nobody recorded.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations DROP tourist_tax_collection');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
