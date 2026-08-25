<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification centre and in-app release notes.
 *
 * `last_seen_version` stays NULL on purpose for existing users. NULL means "never
 * announced", so everybody sees the notes for the version that introduces this
 * feature. The column is written when the user opens the notes from the bell and
 * closes them again — there is no auto-opening popup.
 *
 * Fresh installations do not need the announcement — FirstRunCommand stamps the
 * current version on the initial administrator instead.
 *
 * `notifications` holds installation-wide entries; `notification_reads` tracks who
 * has already seen each one, because fewohbee runs one database per property and
 * two members of staff read independently.
 *
 * Titles are stored as a translation key plus JSON parameters rather than as
 * finished text, so an entry renders in German and English from one row and a
 * wording change needs no data migration. `note` carries the free-text
 * explanation the operator writes on the automation.
 *
 * Also seeds two system workflows that put online bookings and calendar imports
 * into the bell, mirroring the existing notify_* email workflows so either
 * channel can be switched off on its own.
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.last_seen_version, the notifications tables and the in-app notification workflows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_seen_version VARCHAR(20) DEFAULT NULL');

        $this->addSql('CREATE TABLE notifications (
            id INT AUTO_INCREMENT NOT NULL,
            type VARCHAR(64) NOT NULL,
            severity VARCHAR(16) NOT NULL,
            title_key VARCHAR(191) NOT NULL,
            params JSON DEFAULT NULL,
            route_name VARCHAR(191) DEFAULT NULL,
            route_params JSON DEFAULT NULL,
            required_role VARCHAR(64) DEFAULT NULL,
            entity_class VARCHAR(255) DEFAULT NULL,
            entity_id VARCHAR(64) DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_notifications_created (created_at),
            INDEX idx_notifications_entity (entity_class, entity_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE notification_reads (
            id INT AUTO_INCREMENT NOT NULL,
            notification_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_notification_read (notification_id, user_id),
            INDEX IDX_notification_reads_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE notification_reads ADD CONSTRAINT FK_notification_reads_notification
            FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE notification_reads ADD CONSTRAINT FK_notification_reads_user
            FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');

        $this->seedWorkflows();
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM workflows WHERE system_code IN ('notify_online_booking_inapp', 'notify_calendar_import_inapp')");
        $this->addSql('ALTER TABLE notification_reads DROP FOREIGN KEY FK_notification_reads_notification');
        $this->addSql('ALTER TABLE notification_reads DROP FOREIGN KEY FK_notification_reads_user');
        $this->addSql('DROP TABLE notification_reads');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('ALTER TABLE users DROP last_seen_version');
    }

    public function isTransactional(): bool
    {
        // DDL and DML in one migration; MySQL cannot roll DDL back anyway.
        return false;
    }

    /**
     * Names and descriptions are translation keys — WorkflowSeeder resolves them
     * on the next run, the same way it does for the existing system workflows.
     */
    private function seedWorkflows(): void
    {
        $config = json_encode(['severity' => 'info', 'requiredRole' => '']);

        foreach ([
            ['notify_online_booking_inapp', 'online_booking.created'],
            ['notify_calendar_import_inapp', 'calendar_import.created'],
        ] as [$systemCode, $trigger]) {
            $this->addSql(
                "INSERT INTO workflows (system_code, name, description, trigger_type, trigger_config, conditions, action_type, action_config, is_enabled, is_system, created_at, updated_at)
                 SELECT :code, :name, '', :trigger, '[]', '[]', 'create_in_app_notification', :config, 1, 1, NOW(), NOW()
                 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM workflows w WHERE w.system_code = :code)",
                [
                    'code' => $systemCode,
                    'name' => 'workflow.system.' . $systemCode . '.name',
                    'trigger' => $trigger,
                    'config' => $config,
                ]
            );
        }
    }
}
