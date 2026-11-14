<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018150000 extends AbstractMigration
{
    /** Tables whose operator texts guests read, each with a `translations` JSON column. */
    private const TRANSLATABLE_TABLES = [
        'online_booking_config',
        'guest_check_in_config',
        'room_category',
        'appartments',
        'prices',
        'guest_categories',
        'tourist_taxes',
        'objects',
    ];

    /**
     * Built-in salutation keys that bookings through the API stored as they were, in the
     * wording a German installation stores (and its forms always stored).
     */
    private const GERMAN_SALUTATIONS = ['Ms' => 'Frau', 'Mr' => 'Herr', 'Family' => 'Familie'];

    public function getDescription(): string
    {
        return 'Add guest languages: translations of operator texts and salutations, the languages offered to guests, a mail language per guest and language variants of templates';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TRANSLATABLE_TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD translations JSON DEFAULT NULL', $table));
        }
        $this->addSql('ALTER TABLE app_settings ADD guest_locales JSON DEFAULT NULL, ADD customer_salutation_translations JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE customers ADD locale VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE templates ADD locale VARCHAR(10) DEFAULT NULL, ADD base_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE templates ADD CONSTRAINT FK_6F287D8ECFFF940 FOREIGN KEY (base_template_id) REFERENCES templates (id)');
        $this->addSql('CREATE INDEX IDX_6F287D8ECFFF940 ON templates (base_template_id)');

        // Guests booked through the API or an AI assistant kept the key ("Mr") where every form
        // stores the installation's wording ("Herr"); letters greeted them in English. Only exact
        // keys change, and only where the installation runs in German.
        $locale = (string) ($_SERVER['LOCALE'] ?? $_ENV['LOCALE'] ?? getenv('LOCALE') ?: '');
        if (str_starts_with($locale, 'de')) {
            foreach (self::GERMAN_SALUTATIONS as $key => $wording) {
                $this->addSql('UPDATE customers SET salutation = ? WHERE salutation = ?', [$wording, $key]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // The old schema has no place for translations; refuse instead of dropping them silently.
        foreach (self::TRANSLATABLE_TABLES as $table) {
            $translated = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE translations IS NOT NULL', $table));
            $this->abortIf($translated > 0, 'Remove all translations of guest texts before downgrading.');
        }
        $salutationWordings = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM app_settings WHERE customer_salutation_translations IS NOT NULL');
        $this->abortIf($salutationWordings > 0, 'Remove the translated salutations in the general settings before downgrading.');
        $withLanguage = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM customers WHERE locale IS NOT NULL');
        $this->abortIf($withLanguage > 0, 'Guests have a mail language that the previous version cannot store; clear customers.locale before downgrading.');
        // A variant would become an unrelated template of its own; refuse rather than mix them up.
        $variants = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM templates WHERE base_template_id IS NOT NULL OR locale IS NOT NULL');
        $this->abortIf($variants > 0, 'Delete the language variants of templates before downgrading.');

        foreach (self::TRANSLATABLE_TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP translations', $table));
        }
        // The salutation wording stays: the previous version stores and prints it the same way.
        $this->addSql('ALTER TABLE app_settings DROP guest_locales, DROP customer_salutation_translations');
        $this->addSql('ALTER TABLE customers DROP locale');
        $this->addSql('ALTER TABLE templates DROP FOREIGN KEY FK_6F287D8ECFFF940');
        $this->addSql('DROP INDEX IDX_6F287D8ECFFF940 ON templates');
        $this->addSql('ALTER TABLE templates DROP locale, DROP base_template_id');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
