<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add housekeeping room day statuses and role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE room_day_statuses (id INT AUTO_INCREMENT NOT NULL, appartment_id INT NOT NULL, assigned_to_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, date DATE NOT NULL, hk_status VARCHAR(20) NOT NULL, note LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_room_day (appartment_id, date), INDEX IDX_1C76B19393362AA5 (appartment_id), INDEX IDX_1C76B193F91F2105 (assigned_to_id), INDEX IDX_1C76B193896DBBDE (updated_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE room_day_statuses ADD CONSTRAINT FK_1C76B19393362AA5 FOREIGN KEY (appartment_id) REFERENCES appartments (id)');
        $this->addSql('ALTER TABLE room_day_statuses ADD CONSTRAINT FK_1C76B193F91F2105 FOREIGN KEY (assigned_to_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE room_day_statuses ADD CONSTRAINT FK_1C76B193896DBBDE FOREIGN KEY (updated_by_id) REFERENCES users (id)');
        $this->addSql('INSERT INTO roles (id, name, role) VALUES (NULL, "Housekeeping", "ROLE_HOUSEKEEPING")');
        $this->addSql("INSERT INTO template_types (name, icon, service, editor_template) SELECT 'TEMPLATE_OPERATIONS_PDF', 'fa-file-pdf', 'OperationsReportService', 'editor_template_operations.json.twig' WHERE NOT EXISTS (SELECT 1 FROM template_types WHERE name = 'TEMPLATE_OPERATIONS_PDF')");
        $this->addSql("INSERT INTO template_types (name, icon, service, editor_template) SELECT 'TEMPLATE_REGISTRATION_PDF', 'fa-file-pdf', 'ReservationService', 'editor_template_reservation.json.twig' WHERE NOT EXISTS (SELECT 1 FROM template_types WHERE name = 'TEMPLATE_REGISTRATION_PDF')");
    
        $this->addSql('ALTER TABLE reservation_status ADD code VARCHAR(50) DEFAULT NULL, ADD is_blocking TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_RESERVATION_STATUS_CODE ON reservation_status (code)');
        $this->addSql("UPDATE reservation_status SET is_blocking = 1 WHERE is_blocking IS NULL");
        $this->addSql("INSERT INTO reservation_status (name, color, contrast_color, code, is_blocking) SELECT 'Storniert / No-Show', '#6c757d', '#ffffff', 'canceled_noshow', 0 WHERE NOT EXISTS (SELECT 1 FROM reservation_status WHERE code = 'canceled_noshow')");
    
         $this->addSql('ALTER TABLE correspondence ADD binary_payload LONGBLOB DEFAULT NULL');

         $this->addSql('ALTER TABLE template_types DROP service, DROP editor_template');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room_day_statuses');
        $this->addSql('DELETE FROM roles WHERE roles.role = "ROLE_HOUSEKEEPING"');
        $this->addSql("DELETE FROM template_types WHERE name = 'TEMPLATE_OPERATIONS_PDF'");
        $this->addSql("DELETE FROM template_types WHERE name = 'TEMPLATE_REGISTRATION_PDF'");
        
        $this->addSql("DELETE FROM reservation_status WHERE code = 'canceled_noshow'");
        $this->addSql('DROP INDEX UNIQ_RESERVATION_STATUS_CODE ON reservation_status');
        $this->addSql('ALTER TABLE reservation_status DROP code, DROP is_blocking');

        $this->addSql('ALTER TABLE correspondence DROP binary_payload');

        $this->addSql("ALTER TABLE template_types ADD service VARCHAR(150) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE template_types ADD editor_template VARCHAR(50) DEFAULT NULL');
    
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
