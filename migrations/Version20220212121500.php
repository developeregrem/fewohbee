<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20220212121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE calendar_sync (id INT AUTO_INCREMENT NOT NULL, apartment_id INT NOT NULL, uuid BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', is_public TINYINT(1) NOT NULL, last_export DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_7EBD762ED17F50A6 (uuid), UNIQUE INDEX UNIQ_7EBD762E176DFE85 (apartment_id), INDEX uuid_idx (uuid), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE calendar_sync_reservation_status (calendar_sync_id INT NOT NULL, reservation_status_id INT NOT NULL, INDEX IDX_88FEA5C99827BFE5 (calendar_sync_id), INDEX IDX_88FEA5C971B06122 (reservation_status_id), PRIMARY KEY(calendar_sync_id, reservation_status_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calendar_sync ADD CONSTRAINT FK_7EBD762E176DFE85 FOREIGN KEY (apartment_id) REFERENCES appartments (id)');
        $this->addSql('ALTER TABLE calendar_sync_reservation_status ADD CONSTRAINT FK_88FEA5C99827BFE5 FOREIGN KEY (calendar_sync_id) REFERENCES calendar_sync (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_sync_reservation_status ADD CONSTRAINT FK_88FEA5C971B06122 FOREIGN KEY (reservation_status_id) REFERENCES reservation_status (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE calendar_sync ADD export_guest_name TINYINT(1) NOT NULL');

        $this->addSql('ALTER TABLE reservations CHANGE reservation_date reservation_date DATETIME NOT NULL');

        $this->addSql('ALTER TABLE reservations ADD uuid BINARY(16) COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4DA239D17F50A6 ON reservations (uuid)');
        $this->addSql('CREATE INDEX idx_uuid ON reservations (uuid)');

        $sql = 'SELECT id FROM reservations';
        $ids = $this->connection->fetchAllAssociative($sql);
        foreach ($ids as $id) {
            $uuid = Uuid::v4();
            $this->addSql('UPDATE reservations SET uuid = ? WHERE id = ? ', [$uuid->toBinary(), $id['id']]);
        }

        $sql = 'SELECT id FROM appartments';
        $ids = $this->connection->fetchAllAssociative($sql);
        foreach ($ids as $id) {
            $uuid = Uuid::v4();
            $this->addSql('INSERT INTO calendar_sync (apartment_id, uuid, is_public, export_guest_name) VALUES (?, ?, ?, ?)', [$id['id'], $uuid->toBinary(), 0, 0]);
        }
        $this->addSql('ALTER TABLE reservations CHANGE uuid uuid BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar_sync_reservation_status DROP FOREIGN KEY FK_88FEA5C99827BFE5');
        $this->addSql('DROP TABLE calendar_sync');
        $this->addSql('DROP TABLE calendar_sync_reservation_status');
        $this->addSql('ALTER TABLE reservations CHANGE reservation_date reservation_date DATE NOT NULL');
        $this->addSql('DROP INDEX UNIQ_4DA239D17F50A6 ON reservations');
        $this->addSql('DROP INDEX idx_uuid ON reservations');
        $this->addSql('ALTER TABLE reservations DROP uuid');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
