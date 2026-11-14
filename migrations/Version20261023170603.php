<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mails sent straight from the reservation dialog, both unset by default:
 *
 * - guest_check_in_config.invitation_template: the online check-in invitation;
 * - app_settings.confirmation_template, confirmation_send_immediately and the attachment table:
 *   the booking confirmation the button opens, with its PDF attachments.
 */
final class Version20261023170603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the mail templates the reservation dialog sends as online check-in invitation and as booking confirmation with its attachments, unset by default';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_check_in_config ADD invitation_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE guest_check_in_config ADD CONSTRAINT FK_962C8FEDD2D03B8 FOREIGN KEY (invitation_template_id) REFERENCES templates (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_962C8FEDD2D03B8 ON guest_check_in_config (invitation_template_id)');

        $this->addSql('ALTER TABLE app_settings ADD confirmation_template_id INT DEFAULT NULL, ADD confirmation_send_immediately TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE app_settings ADD CONSTRAINT FK_EDC5C0684499135E FOREIGN KEY (confirmation_template_id) REFERENCES templates (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EDC5C0684499135E ON app_settings (confirmation_template_id)');
        $this->addSql('CREATE TABLE app_settings_confirmation_attachments (app_settings_id INT NOT NULL, template_id INT NOT NULL, INDEX IDX_6B02F94671BE34 (app_settings_id), INDEX IDX_6B02F9465DA0FB8 (template_id), PRIMARY KEY (app_settings_id, template_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE app_settings_confirmation_attachments ADD CONSTRAINT FK_6B02F94671BE34 FOREIGN KEY (app_settings_id) REFERENCES app_settings (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE app_settings_confirmation_attachments ADD CONSTRAINT FK_6B02F9465DA0FB8 FOREIGN KEY (template_id) REFERENCES templates (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $invitation = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM guest_check_in_config WHERE invitation_template_id IS NOT NULL');
        $this->abortIf($invitation > 0, 'Clear "Template for the invitation" in the online check-in settings before downgrading.');
        $confirmation = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_settings WHERE confirmation_template_id IS NOT NULL')
            + (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_settings_confirmation_attachments');
        $this->abortIf($confirmation > 0, 'Clear the booking confirmation templates in the general settings before downgrading.');

        $this->addSql('DROP TABLE app_settings_confirmation_attachments');
        $this->addSql('ALTER TABLE app_settings DROP FOREIGN KEY FK_EDC5C0684499135E');
        $this->addSql('DROP INDEX IDX_EDC5C0684499135E ON app_settings');
        $this->addSql('ALTER TABLE app_settings DROP confirmation_template_id, DROP confirmation_send_immediately');

        $this->addSql('ALTER TABLE guest_check_in_config DROP FOREIGN KEY FK_962C8FEDD2D03B8');
        $this->addSql('DROP INDEX IDX_962C8FEDD2D03B8 ON guest_check_in_config');
        $this->addSql('ALTER TABLE guest_check_in_config DROP invitation_template_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
