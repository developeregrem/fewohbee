<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260402073403 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create workflow and workflow_logs tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflows (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, description LONGTEXT DEFAULT NULL, is_enabled TINYINT DEFAULT 1 NOT NULL, is_system TINYINT DEFAULT 0 NOT NULL, system_code VARCHAR(80) DEFAULT NULL, trigger_type VARCHAR(80) NOT NULL, trigger_config JSON NOT NULL, condition_type VARCHAR(80) DEFAULT NULL, condition_config JSON DEFAULT NULL, action_type VARCHAR(80) NOT NULL, action_config JSON NOT NULL, priority INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_EFBFBFC266D9836E (system_code), INDEX idx_workflow_trigger_type (trigger_type), INDEX idx_workflow_system_code (system_code), INDEX idx_workflow_enabled (is_enabled), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workflow_logs (id INT AUTO_INCREMENT NOT NULL, workflow_id INT DEFAULT NULL, workflow_name VARCHAR(150) NOT NULL, trigger_type VARCHAR(80) NOT NULL, entity_class VARCHAR(255) DEFAULT NULL, entity_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, message LONGTEXT DEFAULT NULL, executed_at DATETIME NOT NULL, INDEX idx_wflog_workflow (workflow_id), INDEX idx_wflog_executed_at (executed_at), INDEX idx_wflog_dedup (workflow_id, entity_class, entity_id, status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE workflow_logs ADD CONSTRAINT FK_B510D6672C7C2CBA FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE SET NULL');

        // add internal system workflows
        $this->addSql("INSERT IGNORE INTO workflows
            (name, description, is_enabled, is_system, system_code, trigger_type, trigger_config, condition_type, condition_config, action_type, action_config, priority, created_at, updated_at)
            VALUES
            ('workflow.system.notify_online_booking.name', 'workflow.system.notify_online_booking.description', 1, 1, 'notify_online_booking', 'online_booking.created', '[]', NULL, NULL, 'send_notification_email', '[]', 0, NOW(), NOW()),
            ('workflow.system.notify_calendar_import.name', 'workflow.system.notify_calendar_import.description', 1, 1, 'notify_calendar_import', 'calendar_import.created', '[]', NULL, NULL, 'send_notification_email', '[]', 0, NOW(), NOW())
        ");

        // Add TEMPLATE_INVOICE_EMAIL template type
        $this->addSql("INSERT IGNORE INTO template_types (name, icon) VALUES ('TEMPLATE_INVOICE_EMAIL', 'fa-envelope')");
        // Add TEMPLATE_GENERAL_EMAIL template type for entity-less email workflows
        $this->addSql("INSERT IGNORE INTO template_types (name, icon) VALUES ('TEMPLATE_GENERAL_EMAIL', 'fa-envelope')");
        // Remove legacy notify_on_online_booking and notify_on_calendar_import columns from app_settings (replaced by system workflows)
        $this->addSql('ALTER TABLE app_settings DROP notify_on_online_booking, DROP notify_on_calendar_import');

        // Add indexes on reservations.start_date, reservations.end_date and invoices.date for workflow trigger queries
        $this->addSql('CREATE INDEX idx_reservation_start_date ON reservations (start_date)');
        $this->addSql('CREATE INDEX idx_reservation_end_date ON reservations (end_date)');
        $this->addSql('CREATE INDEX idx_invoice_date ON invoices (date)');

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_logs DROP FOREIGN KEY FK_B510D6672C7C2CBA');
        $this->addSql('DROP TABLE workflow_logs');
        $this->addSql('DROP TABLE workflows');

        $this->addSql("DELETE FROM template_types WHERE name = 'TEMPLATE_INVOICE_EMAIL'");
        $this->addSql("DELETE FROM template_types WHERE name = 'TEMPLATE_GENERAL_EMAIL'");

        $this->addSql('ALTER TABLE app_settings ADD notify_on_online_booking TINYINT DEFAULT 1 NOT NULL, ADD notify_on_calendar_import TINYINT DEFAULT 1 NOT NULL');

        $this->addSql('DROP INDEX idx_reservation_start_date ON reservations');
        $this->addSql('DROP INDEX idx_reservation_end_date ON reservations');
        $this->addSql('DROP INDEX idx_invoice_date ON invoices');
    }
}
