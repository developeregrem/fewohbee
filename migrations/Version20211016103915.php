<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20211016103915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $sql = "SELECT COUNT(*) FROM reservations";
        $count = $this->connection->fetchOne($sql);

        $this->addSql('CREATE TABLE reservation_status (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(7) NOT NULL, contrast_color VARCHAR(7) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        // execute only for existing installations, not for new ones
        if($count > 0) {
            $this->addSql("INSERT INTO reservation_status (name, color, contrast_color) VALUES ('Bestätigt', '#2D9434', '#ffffff')");
            $this->addSql("INSERT INTO reservation_status (name, color, contrast_color) VALUES ('Option', '#f6e95c', '#000000')");
        }
        $this->addSql('ALTER TABLE reservations ADD reservation_status_id INT NOT NULL');
        $this->addSql("UPDATE reservations set reservation_status_id=status");
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA23971B06122 FOREIGN KEY (reservation_status_id) REFERENCES reservation_status (id)');
        $this->addSql('CREATE INDEX IDX_4DA23971B06122 ON reservations (reservation_status_id)');
        $this->addSql("ALTER TABLE reservations DROP status");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA23971B06122');
        $this->addSql('DROP TABLE reservation_status');
        $this->addSql('DROP INDEX IDX_4DA23971B06122 ON reservations');
        $this->addSql('ALTER TABLE reservations DROP reservation_status_id');
    }
    
    public function isTransactional(): bool
    {
        return false;
    }
}
