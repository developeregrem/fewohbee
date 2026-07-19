<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add requires_document_number to booking_entries (marks entries whose document reference is still to be supplied)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_entries ADD requires_document_number TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_entries DROP requires_document_number');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
