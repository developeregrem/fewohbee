<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20190802123327 extends AbstractMigration
{
    public function getDescription() : string
    {
        return '';
    }

    public function up(Schema $schema) : void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $platform instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE appartments (id INT AUTO_INCREMENT NOT NULL, object_id INT DEFAULT NULL, number VARCHAR(10) NOT NULL, beds_min SMALLINT NOT NULL, beds_max SMALLINT NOT NULL, description VARCHAR(255) NOT NULL, INDEX IDX_A67E5E3A232D562B (object_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cash_journal (id INT AUTO_INCREMENT NOT NULL, cash_year SMALLINT UNSIGNED NOT NULL, cash_month SMALLINT UNSIGNED NOT NULL, cash_start NUMERIC(13, 2) NOT NULL, cash_end NUMERIC(13, 2) DEFAULT NULL, is_closed TINYINT(1) NOT NULL, is_booked TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE cash_journal_entries (id INT AUTO_INCREMENT NOT NULL, cash_journal_id INT DEFAULT NULL, incomes NUMERIC(13, 2) DEFAULT NULL, expenses NUMERIC(13, 2) DEFAULT NULL, inventory NUMERIC(13, 2) DEFAULT NULL, counter_account VARCHAR(50) DEFAULT NULL, invoice_number VARCHAR(50) DEFAULT NULL, document_number INT NOT NULL, date DATE NOT NULL, remark TINYTEXT DEFAULT NULL, INDEX IDX_E92E3C1DF3608108 (cash_journal_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE correspondence (id INT AUTO_INCREMENT NOT NULL, template_id INT DEFAULT NULL, reservation_id INT DEFAULT NULL, name VARCHAR(100) NOT NULL, text LONGTEXT NOT NULL, created DATETIME NOT NULL, discr VARCHAR(255) NOT NULL, recipient VARCHAR(100) DEFAULT NULL, subject VARCHAR(200) DEFAULT NULL, file_name VARCHAR(100) DEFAULT NULL, INDEX IDX_2A0046B05DA0FB8 (template_id), INDEX IDX_2A0046B0B83297E7 (reservation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE correspondence_correspondence (correspondence_source INT NOT NULL, correspondence_target INT NOT NULL, INDEX IDX_3F24C58B9042717B (correspondence_source), INDEX IDX_3F24C58B89A721F4 (correspondence_target), PRIMARY KEY(correspondence_source, correspondence_target)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE customers (id INT AUTO_INCREMENT NOT NULL, salutation VARCHAR(20) NOT NULL, firstname VARCHAR(45) DEFAULT NULL, lastname VARCHAR(45) NOT NULL, birthday DATE DEFAULT NULL, company VARCHAR(255) DEFAULT NULL, address VARCHAR(150) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, city VARCHAR(45) DEFAULT NULL, country VARCHAR(45) DEFAULT NULL, phone VARCHAR(45) DEFAULT NULL, fax VARCHAR(45) DEFAULT NULL, mobile_phone VARCHAR(45) DEFAULT NULL, email VARCHAR(100) DEFAULT NULL, remark VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE customer_has_address (customer_id INT NOT NULL, customer_addresses_id INT NOT NULL, INDEX IDX_69F42C529395C3F3 (customer_id), INDEX IDX_69F42C5259CB7F99 (customer_addresses_id), PRIMARY KEY(customer_id, customer_addresses_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE customer_addresses (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(50) NOT NULL, company VARCHAR(255) DEFAULT NULL, address VARCHAR(150) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, city VARCHAR(45) DEFAULT NULL, country VARCHAR(45) DEFAULT NULL, phone VARCHAR(45) DEFAULT NULL, fax VARCHAR(45) DEFAULT NULL, mobile_phone VARCHAR(45) DEFAULT NULL, email VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoices (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(45) NOT NULL, date DATE NOT NULL, salutation VARCHAR(20) DEFAULT NULL, firstname VARCHAR(45) DEFAULT NULL, lastname VARCHAR(45) DEFAULT NULL, company VARCHAR(255) DEFAULT NULL, address VARCHAR(150) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, city VARCHAR(45) DEFAULT NULL, remark LONGTEXT DEFAULT NULL, payment VARCHAR(45) DEFAULT NULL, status SMALLINT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_appartments (id BIGINT AUTO_INCREMENT NOT NULL, invoice_id INT DEFAULT NULL, number VARCHAR(10) NOT NULL, description VARCHAR(255) NOT NULL, beds SMALLINT NOT NULL, persons SMALLINT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, price NUMERIC(10, 2) NOT NULL, vat NUMERIC(10, 2) NOT NULL, INDEX IDX_B1303C092989F1FD (invoice_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_positions (id BIGINT AUTO_INCREMENT NOT NULL, invoice_id INT DEFAULT NULL, amount INT NOT NULL, description VARCHAR(255) NOT NULL, price NUMERIC(10, 2) NOT NULL, vat NUMERIC(10, 2) NOT NULL, INDEX IDX_B33014E02989F1FD (invoice_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE logging (user_id INT NOT NULL, date TIME NOT NULL, action VARCHAR(255) NOT NULL, PRIMARY KEY(user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE opengeodb_de_plz (plz VARCHAR(5) NOT NULL, loc_id INT NOT NULL, lon DOUBLE PRECISION NOT NULL, lat DOUBLE PRECISION NOT NULL, ort VARCHAR(30) NOT NULL, UNIQUE INDEX loc_id (loc_id), PRIMARY KEY(plz)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE prices (id INT AUTO_INCREMENT NOT NULL, price NUMERIC(10, 2) NOT NULL, vat NUMERIC(10, 2) NOT NULL, description VARCHAR(100) NOT NULL, number_of_beds SMALLINT DEFAULT NULL, number_of_persons SMALLINT DEFAULT NULL, min_stay SMALLINT DEFAULT NULL, active TINYINT(1) DEFAULT NULL, season_start DATE DEFAULT NULL, season_end DATE DEFAULT NULL, monday TINYINT(1) DEFAULT NULL, tuesday TINYINT(1) DEFAULT NULL, wednesday TINYINT(1) DEFAULT NULL, thursday TINYINT(1) DEFAULT NULL, friday TINYINT(1) DEFAULT NULL, saturday TINYINT(1) DEFAULT NULL, sunday TINYINT(1) DEFAULT NULL, all_days TINYINT(1) DEFAULT NULL, type SMALLINT NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE prices_has_reservation_origins (price_id INT NOT NULL, reservation_origin_id INT NOT NULL, INDEX IDX_3DE1EC6AD614C7E7 (price_id), INDEX IDX_3DE1EC6A4CE51253 (reservation_origin_id), PRIMARY KEY(price_id, reservation_origin_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE registration_book (id INT AUTO_INCREMENT NOT NULL, customer_id INT DEFAULT NULL, reservation_id INT DEFAULT NULL, number VARCHAR(10) NOT NULL, date DATETIME NOT NULL, salutation VARCHAR(20) DEFAULT NULL, firstname VARCHAR(45) DEFAULT NULL, lastname VARCHAR(45) NOT NULL, company VARCHAR(255) DEFAULT NULL, birthday DATE DEFAULT NULL, address VARCHAR(150) DEFAULT NULL, zip VARCHAR(10) DEFAULT NULL, city VARCHAR(45) DEFAULT NULL, country VARCHAR(45) DEFAULT NULL, year VARCHAR(4) NOT NULL, INDEX IDX_4DBB4ED9395C3F3 (customer_id), INDEX IDX_4DBB4EDB83297E7 (reservation_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reservations (id INT AUTO_INCREMENT NOT NULL, appartment_id INT DEFAULT NULL, booker_id INT DEFAULT NULL, reservation_origin_id INT DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, persons SMALLINT NOT NULL, status SMALLINT NOT NULL, option_date DATE DEFAULT NULL, remark LONGTEXT DEFAULT NULL, reservation_date DATE NOT NULL, INDEX IDX_4DA2392714DC20 (appartment_id), INDEX IDX_4DA2398B7E4006 (booker_id), INDEX IDX_4DA2394CE51253 (reservation_origin_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reservations_has_invoices (reservation_id INT NOT NULL, invoice_id INT NOT NULL, INDEX IDX_28EF2EA0B83297E7 (reservation_id), INDEX IDX_28EF2EA02989F1FD (invoice_id), PRIMARY KEY(reservation_id, invoice_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reservations_has_customers (reservation_id INT NOT NULL, customer_id INT NOT NULL, INDEX IDX_34A24A03B83297E7 (reservation_id), INDEX IDX_34A24A039395C3F3 (customer_id), PRIMARY KEY(reservation_id, customer_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reservation_origins (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE roles (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(30) NOT NULL, role VARCHAR(20) NOT NULL, UNIQUE INDEX UNIQ_B63E2EC757698A6A (role), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE special_days (special_days_id INT AUTO_INCREMENT NOT NULL, start TIME NOT NULL, end TIME NOT NULL, name VARCHAR(45) NOT NULL, PRIMARY KEY(special_days_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE objects (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(45) NOT NULL, description VARCHAR(255) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE templates (id INT AUTO_INCREMENT NOT NULL, template_type_id INT DEFAULT NULL, name VARCHAR(100) NOT NULL, text LONGTEXT NOT NULL, params VARCHAR(255) DEFAULT NULL, is_default TINYINT(1) NOT NULL, INDEX IDX_6F287D8E96F4F7AA (template_type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE template_types (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, icon VARCHAR(50) NOT NULL, service VARCHAR(150) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, role_id INT DEFAULT NULL, username VARCHAR(45) NOT NULL, firstname VARCHAR(45) NOT NULL, lastname VARCHAR(45) NOT NULL, email VARCHAR(100) NOT NULL, password VARCHAR(200) NOT NULL, last_action DATETIME DEFAULT NULL, active TINYINT(1) NOT NULL, salt VARCHAR(40) DEFAULT NULL, INDEX IDX_1483A5E9D60322AC (role_id), UNIQUE INDEX u_username (username), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE appartments ADD CONSTRAINT FK_A67E5E3A232D562B FOREIGN KEY (object_id) REFERENCES objects (id)');
        $this->addSql('ALTER TABLE cash_journal_entries ADD CONSTRAINT FK_E92E3C1DF3608108 FOREIGN KEY (cash_journal_id) REFERENCES cash_journal (id)');
        $this->addSql('ALTER TABLE correspondence ADD CONSTRAINT FK_2A0046B05DA0FB8 FOREIGN KEY (template_id) REFERENCES templates (id)');
        $this->addSql('ALTER TABLE correspondence ADD CONSTRAINT FK_2A0046B0B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id)');
        $this->addSql('ALTER TABLE correspondence_correspondence ADD CONSTRAINT FK_3F24C58B9042717B FOREIGN KEY (correspondence_source) REFERENCES correspondence (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE correspondence_correspondence ADD CONSTRAINT FK_3F24C58B89A721F4 FOREIGN KEY (correspondence_target) REFERENCES correspondence (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_has_address ADD CONSTRAINT FK_69F42C529395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customer_has_address ADD CONSTRAINT FK_69F42C5259CB7F99 FOREIGN KEY (customer_addresses_id) REFERENCES customer_addresses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE invoice_appartments ADD CONSTRAINT FK_B1303C092989F1FD FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
        $this->addSql('ALTER TABLE invoice_positions ADD CONSTRAINT FK_B33014E02989F1FD FOREIGN KEY (invoice_id) REFERENCES invoices (id)');
        $this->addSql('ALTER TABLE prices_has_reservation_origins ADD CONSTRAINT FK_3DE1EC6AD614C7E7 FOREIGN KEY (price_id) REFERENCES prices (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE prices_has_reservation_origins ADD CONSTRAINT FK_3DE1EC6A4CE51253 FOREIGN KEY (reservation_origin_id) REFERENCES reservation_origins (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE registration_book ADD CONSTRAINT FK_4DBB4ED9395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id)');
        $this->addSql('ALTER TABLE registration_book ADD CONSTRAINT FK_4DBB4EDB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA2392714DC20 FOREIGN KEY (appartment_id) REFERENCES appartments (id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA2398B7E4006 FOREIGN KEY (booker_id) REFERENCES customers (id)');
        $this->addSql('ALTER TABLE reservations ADD CONSTRAINT FK_4DA2394CE51253 FOREIGN KEY (reservation_origin_id) REFERENCES reservation_origins (id)');
        $this->addSql('ALTER TABLE reservations_has_invoices ADD CONSTRAINT FK_28EF2EA0B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservations_has_invoices ADD CONSTRAINT FK_28EF2EA02989F1FD FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservations_has_customers ADD CONSTRAINT FK_34A24A03B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservations_has_customers ADD CONSTRAINT FK_34A24A039395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE templates ADD CONSTRAINT FK_6F287D8E96F4F7AA FOREIGN KEY (template_type_id) REFERENCES template_types (id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9D60322AC FOREIGN KEY (role_id) REFERENCES roles (id)');
    }

    public function down(Schema $schema) : void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $platform = $this->connection->getDatabasePlatform();
        $this->abortIf(! $platform instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA2392714DC20');
        $this->addSql('ALTER TABLE cash_journal_entries DROP FOREIGN KEY FK_E92E3C1DF3608108');
        $this->addSql('ALTER TABLE correspondence_correspondence DROP FOREIGN KEY FK_3F24C58B9042717B');
        $this->addSql('ALTER TABLE correspondence_correspondence DROP FOREIGN KEY FK_3F24C58B89A721F4');
        $this->addSql('ALTER TABLE customer_has_address DROP FOREIGN KEY FK_69F42C529395C3F3');
        $this->addSql('ALTER TABLE registration_book DROP FOREIGN KEY FK_4DBB4ED9395C3F3');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA2398B7E4006');
        $this->addSql('ALTER TABLE reservations_has_customers DROP FOREIGN KEY FK_34A24A039395C3F3');
        $this->addSql('ALTER TABLE customer_has_address DROP FOREIGN KEY FK_69F42C5259CB7F99');
        $this->addSql('ALTER TABLE invoice_appartments DROP FOREIGN KEY FK_B1303C092989F1FD');
        $this->addSql('ALTER TABLE invoice_positions DROP FOREIGN KEY FK_B33014E02989F1FD');
        $this->addSql('ALTER TABLE reservations_has_invoices DROP FOREIGN KEY FK_28EF2EA02989F1FD');
        $this->addSql('ALTER TABLE prices_has_reservation_origins DROP FOREIGN KEY FK_3DE1EC6AD614C7E7');
        $this->addSql('ALTER TABLE correspondence DROP FOREIGN KEY FK_2A0046B0B83297E7');
        $this->addSql('ALTER TABLE registration_book DROP FOREIGN KEY FK_4DBB4EDB83297E7');
        $this->addSql('ALTER TABLE reservations_has_invoices DROP FOREIGN KEY FK_28EF2EA0B83297E7');
        $this->addSql('ALTER TABLE reservations_has_customers DROP FOREIGN KEY FK_34A24A03B83297E7');
        $this->addSql('ALTER TABLE prices_has_reservation_origins DROP FOREIGN KEY FK_3DE1EC6A4CE51253');
        $this->addSql('ALTER TABLE reservations DROP FOREIGN KEY FK_4DA2394CE51253');
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY FK_1483A5E9D60322AC');
        $this->addSql('ALTER TABLE appartments DROP FOREIGN KEY FK_A67E5E3A232D562B');
        $this->addSql('ALTER TABLE correspondence DROP FOREIGN KEY FK_2A0046B05DA0FB8');
        $this->addSql('ALTER TABLE templates DROP FOREIGN KEY FK_6F287D8E96F4F7AA');
        $this->addSql('DROP TABLE appartments');
        $this->addSql('DROP TABLE cash_journal');
        $this->addSql('DROP TABLE cash_journal_entries');
        $this->addSql('DROP TABLE correspondence');
        $this->addSql('DROP TABLE correspondence_correspondence');
        $this->addSql('DROP TABLE customers');
        $this->addSql('DROP TABLE customer_has_address');
        $this->addSql('DROP TABLE customer_addresses');
        $this->addSql('DROP TABLE invoices');
        $this->addSql('DROP TABLE invoice_appartments');
        $this->addSql('DROP TABLE invoice_positions');
        $this->addSql('DROP TABLE logging');
        $this->addSql('DROP TABLE opengeodb_de_plz');
        $this->addSql('DROP TABLE prices');
        $this->addSql('DROP TABLE prices_has_reservation_origins');
        $this->addSql('DROP TABLE registration_book');
        $this->addSql('DROP TABLE reservations');
        $this->addSql('DROP TABLE reservations_has_invoices');
        $this->addSql('DROP TABLE reservations_has_customers');
        $this->addSql('DROP TABLE reservation_origins');
        $this->addSql('DROP TABLE roles');
        $this->addSql('DROP TABLE special_days');
        $this->addSql('DROP TABLE objects');
        $this->addSql('DROP TABLE templates');
        $this->addSql('DROP TABLE template_types');
        $this->addSql('DROP TABLE users');
    }
    
    public function isTransactional(): bool
    {
        return false;
    }
}
