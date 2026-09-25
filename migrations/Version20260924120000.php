<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Online check-in for guests.
 *
 * - guest_check_in holds the link selector and the guest's submission per reservation;
 * - guest_check_in_config is the single settings row, created here (switched off) so public
 *   requests never have to write it;
 * - app_settings.public_base_url is the address links for guests are built from;
 * - customers.nationality is printed on the registration form.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the online check-in, the public address setting and the customer nationality';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE guest_check_in (id INT AUTO_INCREMENT NOT NULL, selector VARCHAR(22) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, status VARCHAR(16) DEFAULT 'open' NOT NULL, payload JSON DEFAULT NULL, first_submitted_at DATETIME DEFAULT NULL, last_submitted_at DATETIME DEFAULT NULL, applied_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, UNIQUE INDEX UNIQ_EF6CA4869692E25D (selector), UNIQUE INDEX UNIQ_EF6CA486B83297E7 (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('ALTER TABLE guest_check_in ADD CONSTRAINT FK_EF6CA486B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');

        $this->addSql("CREATE TABLE guest_check_in_config (id INT AUTO_INCREMENT NOT NULL, enabled TINYINT DEFAULT 0 NOT NULL, address_mode VARCHAR(10) DEFAULT 'required' NOT NULL, birthday_mode VARCHAR(10) DEFAULT 'required' NOT NULL, nationality_mode VARCHAR(10) DEFAULT 'required' NOT NULL, id_document_mode VARCHAR(10) DEFAULT 'optional' NOT NULL, contact_mode VARCHAR(10) DEFAULT 'optional' NOT NULL, companions_mode VARCHAR(10) DEFAULT 'optional' NOT NULL, intro_text LONGTEXT DEFAULT NULL, privacy_url VARCHAR(255) DEFAULT NULL, privacy_text LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4");
        $this->addSql('INSERT INTO guest_check_in_config (enabled, updated_at) VALUES (0, NOW())');

        $this->addSql('ALTER TABLE app_settings ADD public_base_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE customers ADD nationality VARCHAR(2) DEFAULT NULL');
    }

    /**
     * Refused while nationalities or unreviewed check-in submissions exist: the older schema has
     * no place for them, so rolling back would silently delete guest data.
     */
    public function down(Schema $schema): void
    {
        $nationalities = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customers WHERE nationality IS NOT NULL');
        $this->abortIf($nationalities > 0,
            sprintf('%d customer(s) have a nationality. Rolling back would delete it; clear the field first.', $nationalities));

        $submissions = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM guest_check_in WHERE payload IS NOT NULL');
        $this->abortIf($submissions > 0,
            sprintf('%d online check-in submission(s) are not reviewed yet. Rolling back would delete them; apply or discard them first.', $submissions));

        $this->addSql('ALTER TABLE customers DROP nationality');
        $this->addSql('ALTER TABLE app_settings DROP public_base_url');

        $this->addSql('DROP TABLE guest_check_in_config');

        $this->addSql('ALTER TABLE guest_check_in DROP FOREIGN KEY FK_EF6CA486B83297E7');
        $this->addSql('DROP TABLE guest_check_in');
    }
}
