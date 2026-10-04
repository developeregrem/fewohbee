<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Configurable placeholder text for the comment field of the public booking form.
 *
 * Nullable without default, so existing installations keep an empty comment field.
 */
final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the comment placeholder text to the online booking settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_booking_config ADD comment_placeholder VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_booking_config DROP comment_placeholder');
    }
}
