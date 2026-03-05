<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add online booking configuration and reservation booking group UUID.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE online_booking_config (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, booking_mode VARCHAR(20) NOT NULL DEFAULT 'INQUIRY', subsidiaries_mode VARCHAR(20) NOT NULL DEFAULT 'ALL', selected_subsidiary_ids JSON DEFAULT NULL, rooms_mode VARCHAR(20) NOT NULL DEFAULT 'ALL', selected_room_ids JSON DEFAULT NULL, theme_primary_color VARCHAR(7) NOT NULL DEFAULT '#1f6feb', theme_accent_color VARCHAR(7) DEFAULT NULL, theme_background_color VARCHAR(7) DEFAULT NULL, theme_border_radius_px INT DEFAULT NULL, confirmation_email_template_id INT DEFAULT NULL, inquiry_reservation_status_id INT DEFAULT NULL, booking_reservation_status_id INT DEFAULT NULL, reservation_origin_id INT DEFAULT NULL, payment_terms LONGTEXT DEFAULT NULL, cancellation_terms LONGTEXT DEFAULT NULL, success_message_text LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("INSERT INTO online_booking_config (enabled, booking_mode, subsidiaries_mode, selected_subsidiary_ids, rooms_mode, selected_room_ids, theme_primary_color, theme_accent_color, theme_background_color, theme_border_radius_px, confirmation_email_template_id, inquiry_reservation_status_id, booking_reservation_status_id, reservation_origin_id, payment_terms, cancellation_terms, success_message_text, updated_at) VALUES (0, 'INQUIRY', 'ALL', JSON_ARRAY(), 'ALL', JSON_ARRAY(), '#1f6feb', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NOW())");
        $this->addSql('ALTER TABLE room_category ADD details LONGTEXT DEFAULT NULL');

        $this->addSql("ALTER TABLE reservations ADD booking_group_uuid BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)'");
        $this->addSql('CREATE INDEX idx_booking_group_uuid ON reservations (booking_group_uuid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE online_booking_config');
        $this->addSql('ALTER TABLE room_category DROP details');
        $this->addSql('DROP INDEX idx_booking_group_uuid ON reservations');
        $this->addSql('ALTER TABLE reservations DROP booking_group_uuid');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
