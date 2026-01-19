<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add calendar sync import configuration table and reservation import metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_sync_import (id INT AUTO_INCREMENT NOT NULL, apartment_id INT NOT NULL, reservation_origin_id INT NOT NULL, reservation_status_id INT NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(2048) NOT NULL, is_active TINYINT(1) NOT NULL, conflict_strategy VARCHAR(50) NOT NULL, last_sync_at DATETIME DEFAULT NULL, last_sync_error LONGTEXT DEFAULT NULL, INDEX calendar_sync_import_apartment_idx (apartment_id), INDEX IDX_3E40D8014CE51253 (reservation_origin_id), INDEX IDX_3E40D80171B06122 (reservation_status_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calendar_sync_import ADD CONSTRAINT FK_3E40D80176DFE85 FOREIGN KEY (apartment_id) REFERENCES appartments (id)');
        $this->addSql('ALTER TABLE calendar_sync_import ADD CONSTRAINT FK_3E40D8014CE51253 FOREIGN KEY (reservation_origin_id) REFERENCES reservation_origins (id)');
        $this->addSql('ALTER TABLE calendar_sync_import ADD CONSTRAINT FK_3E40D80171B06122 FOREIGN KEY (reservation_status_id) REFERENCES reservation_status (id)');
        $this->addSql('ALTER TABLE reservations ADD calendar_sync_import_id INT DEFAULT NULL, ADD ref_uid VARCHAR(255) DEFAULT NULL, ADD is_conflict TINYINT(1) NOT NULL DEFAULT 0, ADD is_conflict_ignored TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA23927C0C2C8EA FOREIGN KEY (calendar_sync_import_id) REFERENCES calendar_sync_import (id)');
        $this->addSql('CREATE INDEX IDX_4DA23927C0C2C8EA ON reservations (calendar_sync_import_id)');
        $this->addSql('CREATE INDEX idx_reservations_ref_uid ON reservations (ref_uid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA23927C0C2C8EA');
        $this->addSql('DROP INDEX IDX_4DA23927C0C2C8EA ON reservations');
        $this->addSql('DROP INDEX idx_reservations_ref_uid ON reservations');
        $this->addSql('ALTER TABLE reservations DROP calendar_sync_import_id, DROP ref_uid, DROP is_conflict, DROP is_conflict_ignored');
        $this->addSql('ALTER TABLE calendar_sync_import DROP FOREIGN KEY FK_3E40D8014CE51253');
        $this->addSql('ALTER TABLE calendar_sync_import DROP FOREIGN KEY FK_3E40D80171B06122');
        $this->addSql('ALTER TABLE calendar_sync_import DROP FOREIGN KEY FK_3E40D80176DFE85');
        $this->addSql('DROP TABLE calendar_sync_import');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
