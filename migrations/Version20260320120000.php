<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add online booking restrictions: min stay, min stay overrides, room category limits, booking horizon.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE online_booking_min_stay (
            id INT AUTO_INCREMENT NOT NULL,
            room_category_id INT NOT NULL,
            min_nights_weekday SMALLINT DEFAULT NULL,
            min_nights_weekend SMALLINT DEFAULT NULL,
            INDEX IDX_OB_MIN_STAY_RC (room_category_id),
            UNIQUE INDEX uniq_ob_min_stay_room_category (room_category_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_OB_MIN_STAY_RC FOREIGN KEY (room_category_id) REFERENCES room_category (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE online_booking_min_stay_override (
            id INT AUTO_INCREMENT NOT NULL,
            room_category_id INT DEFAULT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            min_nights SMALLINT NOT NULL,
            INDEX IDX_OB_MIN_STAY_OVR_RC (room_category_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_OB_MIN_STAY_OVR_RC FOREIGN KEY (room_category_id) REFERENCES room_category (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE online_booking_room_category_limit (
            id INT AUTO_INCREMENT NOT NULL,
            room_category_id INT NOT NULL,
            max_rooms SMALLINT DEFAULT NULL,
            min_occupancy SMALLINT DEFAULT NULL,
            INDEX IDX_OB_RC_LIMIT_RC (room_category_id),
            UNIQUE INDEX uniq_ob_room_cat_limit (room_category_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_OB_RC_LIMIT_RC FOREIGN KEY (room_category_id) REFERENCES room_category (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE online_booking_config ADD booking_horizon_months SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE online_booking_min_stay');
        $this->addSql('DROP TABLE online_booking_min_stay_override');
        $this->addSql('DROP TABLE online_booking_room_category_limit');
        $this->addSql('ALTER TABLE online_booking_config DROP booking_horizon_months');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
