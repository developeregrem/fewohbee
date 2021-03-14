<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210227203949 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE reservation_price (reservation_id INT NOT NULL, price_id INT NOT NULL, INDEX IDX_89B7F27EB83297E7 (reservation_id), INDEX IDX_89B7F27ED614C7E7 (price_id), PRIMARY KEY(reservation_id, price_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reservation_price ADD CONSTRAINT FK_89B7F27EB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_price ADD CONSTRAINT FK_89B7F27ED614C7E7 FOREIGN KEY (price_id) REFERENCES prices (id) ON DELETE CASCADE');
        $this->addSql('UPDATE invoice_appartments set includes_vat=1 WHERE 1');
        $this->addSql('UPDATE invoice_positions set includes_vat=1 WHERE 1');
        $this->addSql('UPDATE prices set includes_vat=1 WHERE 1');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE reservation_price');
    }
    
    public function isTransactional(): bool
    {
        return false;
    }
}
