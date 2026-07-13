<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add room blocks (out-of-order periods per room)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE room_blocks (id INT AUTO_INCREMENT NOT NULL, appartment_id INT NOT NULL, created_by_id INT DEFAULT NULL, uuid BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\', start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', reason VARCHAR(255) NOT NULL, note LONGTEXT DEFAULT NULL, source VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8FA353B9D17F50A6 (uuid), INDEX idx_room_blocks_room_dates (appartment_id, start_date, end_date), INDEX IDX_8FA353B92714DC20 (appartment_id), INDEX IDX_8FA353B9B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE room_blocks ADD CONSTRAINT FK_8FA353B92714DC20 FOREIGN KEY (appartment_id) REFERENCES appartments (id)');
        $this->addSql('ALTER TABLE room_blocks ADD CONSTRAINT FK_8FA353B9B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room_blocks');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
