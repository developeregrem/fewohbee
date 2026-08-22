<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'multiple changes, see below for details';
    }

    public function up(Schema $schema): void
    {
        // Add subject column to templates and backfill existing email templates with their name
        $this->addSql('ALTER TABLE templates ADD subject VARCHAR(255) DEFAULT NULL');
        // Preserve previous behaviour where the email subject was derived from the template name.
        $this->addSql("UPDATE templates t
            JOIN template_types tt ON t.template_type_id = tt.id
            SET t.subject = t.name
            WHERE tt.name LIKE '%\\_EMAIL'");

        // Add a color to reservation origins for the reservation overview accent
        $this->addSql('ALTER TABLE reservation_origins ADD color VARCHAR(7) DEFAULT NULL');

        // Add api_tokens table for personal access tokens (REST API)
        $this->addSql('CREATE TABLE api_tokens (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, token_prefix VARCHAR(12) NOT NULL, token_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', user_id INT NOT NULL, UNIQUE INDEX UNIQ_2CAD560EB3BC57DA (token_hash), INDEX IDX_2CAD560EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_2CAD560EA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    
        // Add theme column to online booking config; keep installations that already use online booking on the classic design
        $this->addSql("ALTER TABLE online_booking_config ADD theme VARCHAR(20) DEFAULT 'modern' NOT NULL");
        // Properties already running online booking keep the previous design, because their
        // custom CSS was written against its markup. New installations get the modern theme.
        $this->addSql("UPDATE online_booking_config SET theme = 'classic' WHERE enabled = 1");

        // Add optional start and end time to calendar entries (both null keeps an entry all-day)
        $this->addSql('ALTER TABLE calendar_entry ADD time TIME DEFAULT NULL, ADD end_time TIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE templates DROP subject');
        $this->addSql('ALTER TABLE reservation_origins DROP color');

        $this->addSql('ALTER TABLE api_tokens DROP FOREIGN KEY FK_2CAD560EA76ED395');
        $this->addSql('DROP TABLE api_tokens');

        $this->addSql('ALTER TABLE online_booking_config DROP theme');

        $this->addSql('ALTER TABLE calendar_entry DROP time, DROP end_time');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
