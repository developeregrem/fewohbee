<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * MCP server for AI assistants.
 *
 * - app_settings gets the administrator switches (both off, so nothing changes on upgrade) and the
 *   allowed host names of the endpoint;
 * - mcp_tool_call_log audits every tool call of an AI assistant;
 * - logging records the channel (web/api/mcp) and token prefix of an entity change.
 *
 * The notification workflow for assistant bookings is deliberately not seeded here: it is created
 * when an administrator switches MCP on (WorkflowSeeder::seedAssistantWorkflows()).
 */
final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add MCP settings, the MCP tool call audit log and the change log channel';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_settings ADD mcp_enabled TINYINT DEFAULT 0 NOT NULL, ADD mcp_write_enabled TINYINT DEFAULT 0 NOT NULL, ADD mcp_allowed_hosts JSON DEFAULT NULL');

        $this->addSql('CREATE TABLE mcp_tool_call_log (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, username VARCHAR(180) DEFAULT NULL, token_prefix VARCHAR(12) DEFAULT NULL, tool_name VARCHAR(100) NOT NULL, outcome VARCHAR(16) NOT NULL, duration_ms INT NOT NULL, details JSON DEFAULT NULL, client VARCHAR(100) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_id INT DEFAULT NULL, api_token_id INT DEFAULT NULL, INDEX idx_mcp_tool_call_log_created (created_at), INDEX IDX_F9286F98A76ED395 (user_id), INDEX IDX_F9286F9892E52D36 (api_token_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE mcp_tool_call_log ADD CONSTRAINT FK_F9286F98A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mcp_tool_call_log ADD CONSTRAINT FK_F9286F9892E52D36 FOREIGN KEY (api_token_id) REFERENCES api_tokens (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE logging ADD channel VARCHAR(8) DEFAULT NULL, ADD api_token_prefix VARCHAR(12) DEFAULT NULL');
    }

    /**
     * Drops the MCP audit log and the switches; the assistant notification workflow (if seeded)
     * stays and simply never fires on the older version.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE logging DROP channel, DROP api_token_prefix');

        $this->addSql('ALTER TABLE mcp_tool_call_log DROP FOREIGN KEY FK_F9286F98A76ED395');
        $this->addSql('ALTER TABLE mcp_tool_call_log DROP FOREIGN KEY FK_F9286F9892E52D36');
        $this->addSql('DROP TABLE mcp_tool_call_log');

        $this->addSql('ALTER TABLE app_settings DROP mcp_enabled, DROP mcp_write_enabled, DROP mcp_allowed_hosts');
    }
}
