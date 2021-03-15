<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200229105042 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE room_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE appartments ADD room_category_id INT DEFAULT NULL, DROP beds_min');
        $this->addSql('ALTER TABLE appartments ADD CONSTRAINT FK_A67E5E3A67333DD FOREIGN KEY (room_category_id) REFERENCES room_category (id)');
        $this->addSql('CREATE INDEX IDX_A67E5E3A67333DD ON appartments (room_category_id)');
        $this->addSql('ALTER TABLE opengeodb_de_plz CHANGE plz plz VARCHAR(5) NOT NULL');
        $this->addSql('ALTER TABLE prices ADD room_category_id INT DEFAULT NULL, DROP number_of_beds');
        $this->addSql('ALTER TABLE prices ADD CONSTRAINT FK_E4CB6D5967333DD FOREIGN KEY (room_category_id) REFERENCES room_category (id)');
        $this->addSql('CREATE INDEX IDX_E4CB6D5967333DD ON prices (room_category_id)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE appartments DROP FOREIGN KEY FK_A67E5E3A67333DD');
        $this->addSql('ALTER TABLE prices DROP FOREIGN KEY FK_E4CB6D5967333DD');
        $this->addSql('DROP TABLE room_category');
        $this->addSql('DROP INDEX IDX_A67E5E3A67333DD ON appartments');
        $this->addSql('ALTER TABLE appartments ADD beds_min SMALLINT NOT NULL, DROP room_category_id');
        $this->addSql('ALTER TABLE opengeodb_de_plz CHANGE plz plz VARCHAR(5) CHARACTER SET utf8 NOT NULL COLLATE `utf8_general_ci`');
        $this->addSql('DROP INDEX IDX_E4CB6D5967333DD ON prices');
        $this->addSql('ALTER TABLE prices ADD number_of_beds SMALLINT DEFAULT NULL, DROP room_category_id');
    }
    
    public function isTransactional(): bool
    {
        return false;
    }
}
