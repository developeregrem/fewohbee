<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20210207103847 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE prices ADD includes_vat TINYINT(1) NOT NULL, ADD is_flat_price TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE invoice_appartments ADD includes_vat TINYINT(1) NOT NULL, ADD is_flat_price TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE invoice_positions ADD includes_vat TINYINT(1) NOT NULL, ADD is_flat_price TINYINT(1) NOT NULL');
        $this->addSql('ALTER TABLE invoice_appartments CHANGE includes_vat includes_vat TINYINT(1) DEFAULT NULL, CHANGE is_flat_price is_flat_price TINYINT(1) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_positions CHANGE includes_vat includes_vat TINYINT(1) DEFAULT NULL, CHANGE is_flat_price is_flat_price TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE prices DROP includes_vat, DROP is_flat_price');
        $this->addSql('ALTER TABLE invoice_appartments DROP includes_vat, DROP is_flat_price');
        $this->addSql('ALTER TABLE invoice_positions DROP includes_vat, DROP is_flat_price');
    }
    
    public function isTransactional(): bool
    {
        return false;
    }
}
