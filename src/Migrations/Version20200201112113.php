<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20200201112113 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE price_period (id INT AUTO_INCREMENT NOT NULL, price_id INT NOT NULL, start DATE NOT NULL, end DATE NOT NULL, INDEX IDX_8821B69ED614C7E7 (price_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE price_period ADD CONSTRAINT FK_8821B69ED614C7E7 FOREIGN KEY (price_id) REFERENCES prices (id)');
        $this->addSql('ALTER TABLE opengeodb_de_plz CHANGE plz plz VARCHAR(5) NOT NULL');
        $this->addSql('ALTER TABLE prices ADD all_periods TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE price_period');
        $this->addSql('ALTER TABLE opengeodb_de_plz CHANGE plz plz VARCHAR(5) CHARACTER SET utf8 NOT NULL COLLATE `utf8_general_ci`');
        $this->addSql('ALTER TABLE prices DROP all_periods');
    }
}
