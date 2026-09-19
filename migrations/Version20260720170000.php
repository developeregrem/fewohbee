<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add commission_percent and payment_fee_percent to reservation_origins (the portal fees a guest carries on top of the direct price)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_origins ADD commission_percent NUMERIC(5, 2) DEFAULT NULL, ADD payment_fee_percent NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_origins DROP commission_percent, DROP payment_fee_percent');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
