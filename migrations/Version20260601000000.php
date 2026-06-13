<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TEMPLATE_NEWSLETTER_EMAIL template type.';
    }

    public function up(Schema $schema): void
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM template_types WHERE name = 'TEMPLATE_NEWSLETTER_EMAIL'"
        );
        if ((int) $count === 0) {
            $this->addSql("INSERT INTO template_types (name, icon) VALUES ('TEMPLATE_NEWSLETTER_EMAIL', 'fa-newspaper')");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM template_types WHERE name = 'TEMPLATE_NEWSLETTER_EMAIL'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
