<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subject column to templates and backfill existing email templates with their name';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE templates ADD subject VARCHAR(255) DEFAULT NULL');
        // Preserve previous behaviour where the email subject was derived from the template name.
        $this->addSql("UPDATE templates t
            JOIN template_types tt ON t.template_type_id = tt.id
            SET t.subject = t.name
            WHERE tt.name LIKE '%\\_EMAIL'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE templates DROP subject');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
