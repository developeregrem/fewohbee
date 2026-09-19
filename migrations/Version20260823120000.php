<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Additive schema changes for 4.12.0: notifications, SSO identities, branch information
 * and calendar import filters. Consolidates the unreleased development migrations.
 *
 * Existing users keep NULL in last_seen_version so the release is announced in the bell;
 * FirstRunCommand marks the current version as seen for the initial administrator instead.
 * Notifications are shared across the installation, with read state tracked per user.
 * Titles use translation keys and JSON parameters; notes hold the operator's own text.
 *
 * Opening hours and check-in/out times are display-only branch information. They do not
 * default or overwrite reservation times. A check-in end before its start means the next day.
 * NULL means these optional settings have not been configured.
 *
 * down() removes the new settings, SSO links, notifications and their system workflows.
 * The separate booking-rule and price migrations retain their own data-loss guards.
 */
final class Version20260823120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notifications, OIDC identities, branch opening/check-in times and calendar import filters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_seen_version VARCHAR(20) DEFAULT NULL, ADD oidc_issuer VARCHAR(255) DEFAULT NULL, ADD oidc_subject VARCHAR(255) DEFAULT NULL, ADD oidc_linked_at DATETIME DEFAULT NULL');
        // NULL identities remain valid for existing users, even with the unique index.
        $this->addSql('CREATE UNIQUE INDEX uniq_users_oidc_identity ON users (oidc_issuer, oidc_subject)');

        $this->addSql('ALTER TABLE objects ADD opening_hours JSON DEFAULT NULL, ADD opening_hours_note LONGTEXT DEFAULT NULL, ADD check_in_from TIME DEFAULT NULL, ADD check_in_until TIME DEFAULT NULL, ADD check_out_until TIME DEFAULT NULL, ADD check_in_note LONGTEXT DEFAULT NULL');

        // The entity treats NULL exclusions as an empty list; sharing defaults to enabled.
        $this->addSql('ALTER TABLE calendar_sync_import ADD excluded_summaries JSON DEFAULT NULL, ADD excluded_summary_terms JSON DEFAULT NULL, ADD share_summary_filters TINYINT(1) DEFAULT 1 NOT NULL');

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
        $this->addSql('ALTER TABLE calendar_sync_import DROP excluded_summaries, DROP excluded_summary_terms, DROP share_summary_filters');
        $this->addSql('ALTER TABLE objects DROP check_in_note, DROP check_out_until, DROP check_in_until, DROP check_in_from, DROP opening_hours_note, DROP opening_hours');
        $this->addSql('DROP INDEX uniq_users_oidc_identity ON users');
        $this->addSql('ALTER TABLE users DROP last_seen_version, DROP oidc_issuer, DROP oidc_subject, DROP oidc_linked_at');
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
