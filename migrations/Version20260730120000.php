<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260730120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add commission_percent and payment_fee_percent to reservations (the portal rates as they stood when the reservation was booked)';
    }

    public function up(Schema $schema): void
    {
        // Left null for existing reservations: those were booked before the rates
        // were pinned, and the origin's current rate is the only figure available
        // for them - which is what the deduction falls back to.
        $this->addSql('ALTER TABLE reservations ADD commission_percent NUMERIC(5, 2) DEFAULT NULL, ADD payment_fee_percent NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations DROP commission_percent, DROP payment_fee_percent');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
