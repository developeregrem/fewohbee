<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets a price apply to several room categories instead of exactly one.
 *
 * The single prices.room_category_id column becomes the join table prices_has_room_categories.
 * Every existing assignment is copied over, so a price keeps exactly the category it had; prices
 * without a category (misc prices that apply to every category) simply get no rows.
 */
final class Version20260917120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow prices to be bound to multiple room categories';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prices_has_room_categories (price_id INT NOT NULL, room_category_id INT NOT NULL, INDEX IDX_46FAA4CFD614C7E7 (price_id), INDEX IDX_46FAA4CF67333DD (room_category_id), PRIMARY KEY (price_id, room_category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE prices_has_room_categories ADD CONSTRAINT FK_46FAA4CFD614C7E7 FOREIGN KEY (price_id) REFERENCES prices (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE prices_has_room_categories ADD CONSTRAINT FK_46FAA4CF67333DD FOREIGN KEY (room_category_id) REFERENCES room_category (id) ON DELETE CASCADE');

        $this->addSql('INSERT INTO prices_has_room_categories (price_id, room_category_id) SELECT id, room_category_id FROM prices WHERE room_category_id IS NOT NULL');

        $this->addSql('ALTER TABLE prices DROP FOREIGN KEY FK_E4CB6D5967333DD');
        $this->addSql('DROP INDEX IDX_E4CB6D5967333DD ON prices');
        $this->addSql('ALTER TABLE prices DROP room_category_id');
    }

    /** Refuses to run once a price uses several categories, because the old column holds only one. */
    public function down(Schema $schema): void
    {
        $multiCategoryPrices = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT price_id FROM prices_has_room_categories GROUP BY price_id HAVING COUNT(*) > 1) multi'
        );
        $this->abortIf($multiCategoryPrices > 0,
            'Some prices are bound to several room categories. Reduce them to one category each, or restore the pre-upgrade backup, before downgrading.');

        $this->addSql('ALTER TABLE prices ADD room_category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE prices ADD CONSTRAINT FK_E4CB6D5967333DD FOREIGN KEY (room_category_id) REFERENCES room_category (id)');
        $this->addSql('CREATE INDEX IDX_E4CB6D5967333DD ON prices (room_category_id)');

        $this->addSql('UPDATE prices p JOIN prices_has_room_categories prc ON prc.price_id = p.id SET p.room_category_id = prc.room_category_id');

        $this->addSql('ALTER TABLE prices_has_room_categories DROP FOREIGN KEY FK_46FAA4CFD614C7E7');
        $this->addSql('ALTER TABLE prices_has_room_categories DROP FOREIGN KEY FK_46FAA4CF67333DD');
        $this->addSql('DROP TABLE prices_has_room_categories');
    }
}
