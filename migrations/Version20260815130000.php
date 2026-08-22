<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Moves automatic online-booking confirmations into the workflow engine.
 */
final class Version20260815130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate the online-booking confirmation template to a configurable system workflow';
    }

    public function up(Schema $schema): void
    {
        // Preserve the previous automatic guest email for existing installations.
        // A missing template becomes a disabled workflow and can be configured later.
        $this->addSql(<<<'SQL'
            INSERT INTO workflows
                (name, description, is_enabled, is_system, system_code, trigger_type, trigger_config, conditions, action_type, action_config, priority, created_at, updated_at)
            SELECT
                'workflow.system.confirm_online_booking.name',
                'workflow.system.confirm_online_booking.description',
                IF(c.confirmation_email_template_id IS NULL, 0, 1),
                1,
                'confirm_online_booking',
                'online_booking.created',
                JSON_ARRAY(),
                JSON_ARRAY(JSON_OBJECT('type', 'reservation.has_booker_email', 'config', JSON_OBJECT())),
                'send_template_email',
                JSON_OBJECT(
                    'recipientType', 'booker_email',
                    'templateId', IFNULL(c.confirmation_email_template_id, 0),
                    'customRecipient', '',
                    'attachments', JSON_ARRAY(),
                    'attachmentPolicy', 'skip_missing'
                ),
                0,
                NOW(),
                NOW()
            FROM online_booking_config c
            ORDER BY c.id
            LIMIT 1
            SQL);

        $this->addSql('ALTER TABLE online_booking_config DROP confirmation_email_template_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE online_booking_config ADD confirmation_email_template_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE online_booking_config c
            INNER JOIN workflows w ON w.system_code = 'confirm_online_booking'
            SET c.confirmation_email_template_id = NULLIF(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(w.action_config, '$.templateId')) AS UNSIGNED),
                0
            )
            SQL);
        $this->addSql("DELETE FROM workflows WHERE system_code = 'confirm_online_booking'");
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
