<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023170603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mail template the reservation dialog sends as online check-in invitation, unset by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_check_in_config ADD invitation_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE guest_check_in_config ADD CONSTRAINT FK_962C8FEDD2D03B8 FOREIGN KEY (invitation_template_id) REFERENCES templates (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_962C8FEDD2D03B8 ON guest_check_in_config (invitation_template_id)');
    }

    public function down(Schema $schema): void
    {
        $set = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM guest_check_in_config WHERE invitation_template_id IS NOT NULL');
        $this->abortIf($set > 0, 'Clear "Template for the invitation" in the online check-in settings before downgrading.');

        $this->addSql('ALTER TABLE guest_check_in_config DROP FOREIGN KEY FK_962C8FEDD2D03B8');
        $this->addSql('DROP INDEX IDX_962C8FEDD2D03B8 ON guest_check_in_config');
        $this->addSql('ALTER TABLE guest_check_in_config DROP invitation_template_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
