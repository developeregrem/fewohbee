<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add amenity system and room category images for the public booking page.
 * Seeds ~25 predefined amenities with Font Awesome icon classes.
 */
final class Version20260321120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add amenity table with seed data, room_category_amenity join table, and room_category_image table.';
    }

    public function up(Schema $schema): void
    {
        // Amenity table — predefined amenities seeded below
        $this->addSql('CREATE TABLE amenity (
            id INT AUTO_INCREMENT NOT NULL,
            slug VARCHAR(50) NOT NULL,
            icon_fa_class VARCHAR(100) NOT NULL,
            category VARCHAR(30) NOT NULL,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            booking_com_rma_code VARCHAR(50) DEFAULT NULL,
            airbnb_amenity_id VARCHAR(50) DEFAULT NULL,
            UNIQUE INDEX UNIQ_AB5B5FD6989D9B62 (slug),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Join table for ManyToMany between RoomCategory (owning) and Amenity
        $this->addSql('CREATE TABLE room_category_amenity (
            room_category_id INT NOT NULL,
            amenity_id INT NOT NULL,
            INDEX IDX_RCA_ROOM_CATEGORY (room_category_id),
            INDEX IDX_RCA_AMENITY (amenity_id),
            PRIMARY KEY(room_category_id, amenity_id),
            CONSTRAINT FK_RCA_ROOM_CATEGORY FOREIGN KEY (room_category_id) REFERENCES room_category (id) ON DELETE CASCADE,
            CONSTRAINT FK_RCA_AMENITY FOREIGN KEY (amenity_id) REFERENCES amenity (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Room category images with sort order and primary flag
        $this->addSql('CREATE TABLE room_category_image (
            id INT AUTO_INCREMENT NOT NULL,
            room_category_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            tag VARCHAR(50) DEFAULT NULL,
            INDEX IDX_RCI_ROOM_CATEGORY (room_category_id),
            PRIMARY KEY(id),
            CONSTRAINT FK_RCI_ROOM_CATEGORY FOREIGN KEY (room_category_id) REFERENCES room_category (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Add OTA room type code to room_category for future channel manager mapping
        $this->addSql('ALTER TABLE room_category ADD ota_room_type_code VARCHAR(50) DEFAULT NULL');

        // Seed predefined amenities with Font Awesome icon classes
        $amenities = $this->getAmenitySeedData();
        foreach ($amenities as $a) {
            $this->addSql(
                'INSERT INTO amenity (slug, icon_fa_class, category, sort_order) VALUES (?, ?, ?, ?)',
                [$a['slug'], $a['fa'], $a['cat'], $a['sort']]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE room_category_image');
        $this->addSql('DROP TABLE room_category_amenity');
        $this->addSql('DROP TABLE amenity');
        $this->addSql('ALTER TABLE room_category DROP ota_room_type_code');
    }

    /**
     * Returns seed data for ~25 standard amenities.
     * Each entry contains slug, Font Awesome class, category, and sort order.
     *
     * @return list<array{slug: string, fa: string, cat: string, sort: int}>
     */
    private function getAmenitySeedData(): array
    {
        return [
            // === Room amenities ===
            ['slug' => 'wifi', 'fa' => 'fa-solid fa-wifi', 'cat' => 'room', 'sort' => 1],
            ['slug' => 'tv', 'fa' => 'fa-solid fa-tv', 'cat' => 'room', 'sort' => 2],
            ['slug' => 'air_conditioning', 'fa' => 'fa-solid fa-snowflake', 'cat' => 'room', 'sort' => 3],
            ['slug' => 'heating', 'fa' => 'fa-solid fa-temperature-high', 'cat' => 'room', 'sort' => 4],
            ['slug' => 'safe', 'fa' => 'fa-solid fa-shield-halved', 'cat' => 'room', 'sort' => 5],
            ['slug' => 'desk', 'fa' => 'fa-solid fa-keyboard', 'cat' => 'room', 'sort' => 6],
            ['slug' => 'balcony', 'fa' => 'fa-solid fa-door-open', 'cat' => 'room', 'sort' => 7],
            ['slug' => 'terrace', 'fa' => 'fa-solid fa-house', 'cat' => 'room', 'sort' => 8],
            ['slug' => 'view_mountain', 'fa' => 'fa-solid fa-mountain', 'cat' => 'room', 'sort' => 9],
            ['slug' => 'view_lake', 'fa' => 'fa-solid fa-water', 'cat' => 'room', 'sort' => 10],
            ['slug' => 'view_garden', 'fa' => 'fa-solid fa-tree', 'cat' => 'room', 'sort' => 11],

            // === Bathroom amenities ===
            ['slug' => 'private_bathroom', 'fa' => 'fa-solid fa-toilet', 'cat' => 'bathroom', 'sort' => 1],
            ['slug' => 'shower', 'fa' => 'fa-solid fa-shower', 'cat' => 'bathroom', 'sort' => 2],
            ['slug' => 'bathtub', 'fa' => 'fa-solid fa-bath', 'cat' => 'bathroom', 'sort' => 3],
            ['slug' => 'hairdryer', 'fa' => 'fa-solid fa-wind', 'cat' => 'bathroom', 'sort' => 4],
            ['slug' => 'towels', 'fa' => 'fa-solid fa-hot-tub-person', 'cat' => 'bathroom', 'sort' => 5],

            // === Kitchen amenities ===
            ['slug' => 'kitchenette', 'fa' => 'fa-solid fa-kitchen-set', 'cat' => 'kitchen', 'sort' => 1],
            ['slug' => 'fridge', 'fa' => 'fa-solid fa-box', 'cat' => 'kitchen', 'sort' => 2],
            ['slug' => 'coffee_machine', 'fa' => 'fa-solid fa-mug-hot', 'cat' => 'kitchen', 'sort' => 3],
            ['slug' => 'kettle', 'fa' => 'fa-solid fa-mug-saucer', 'cat' => 'kitchen', 'sort' => 4],
            ['slug' => 'dishwasher', 'fa' => 'fa-solid fa-sink', 'cat' => 'kitchen', 'sort' => 5],

            // === Outdoor / general amenities ===
            ['slug' => 'parking', 'fa' => 'fa-solid fa-square-parking', 'cat' => 'outdoor', 'sort' => 1],
            ['slug' => 'pets_allowed', 'fa' => 'fa-solid fa-paw', 'cat' => 'outdoor', 'sort' => 2],
            ['slug' => 'non_smoking', 'fa' => 'fa-solid fa-ban-smoking', 'cat' => 'outdoor', 'sort' => 3],
            ['slug' => 'accessible', 'fa' => 'fa-solid fa-wheelchair', 'cat' => 'outdoor', 'sort' => 4],
            ['slug' => 'crib_available', 'fa' => 'fa-solid fa-baby', 'cat' => 'outdoor', 'sort' => 5],
        ];
    }
}
