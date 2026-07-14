<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Generalizes the waste-specific waste_collection_date table (and the
 * matching waste_calendar_* fields on app_settings) into a generic
 * calendar / calendar_entry model that supports any number of calendars
 * (waste collection, vacations, events, ...), each with its own color and
 * confirmation-required flag.
 *
 * Existing data is preserved: if a waste calendar was configured, it
 * becomes a Calendar row named "Abfallkalender" (yellow, confirmation
 * required), and every waste_collection_date row becomes a CalendarEntry
 * under it.
 */
final class Version20260713115906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Generalize waste calendar into generic calendar/calendar_entry model';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement('CREATE TABLE calendar (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(20) NOT NULL,
            requires_confirmation TINYINT(1) NOT NULL,
            ics_url VARCHAR(255) DEFAULT NULL,
            ics_filename VARCHAR(255) DEFAULT NULL,
            last_synced_at DATETIME DEFAULT NULL,
            last_sync_count INT DEFAULT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->connection->executeStatement('CREATE TABLE calendar_entry (
            id INT AUTO_INCREMENT NOT NULL,
            calendar_id INT NOT NULL,
            date DATE NOT NULL,
            title VARCHAR(100) NOT NULL,
            ics_uid VARCHAR(255) NOT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_calendar_entry_ics_uid (ics_uid),
            INDEX idx_calendar_entry_date (date),
            INDEX IDX_CALENDAR_ENTRY_CALENDAR (calendar_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->connection->executeStatement('ALTER TABLE calendar_entry ADD CONSTRAINT FK_CALENDAR_ENTRY_CALENDAR FOREIGN KEY (calendar_id) REFERENCES calendar (id) ON DELETE CASCADE');

        // Migrate existing waste calendar config (if any) into a Calendar row,
        // and existing waste_collection_date rows into calendar_entry.
        $settings = $this->connection->fetchAssociative(
            'SELECT waste_calendar_ics_url, waste_calendar_ics_filename, waste_calendar_last_synced_at, waste_calendar_last_sync_count
             FROM app_settings ORDER BY id ASC LIMIT 1'
        );

        $hadWasteConfig = false !== $settings && (null !== $settings['waste_calendar_ics_url'] || null !== $settings['waste_calendar_ics_filename']);
        $hasWasteEntries = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM waste_collection_date') > 0;

        if ($hadWasteConfig || $hasWasteEntries) {
            $this->connection->executeStatement(
                'INSERT INTO calendar (name, color, requires_confirmation, ics_url, ics_filename, last_synced_at, last_sync_count)
                 VALUES (?, ?, 1, ?, ?, ?, ?)',
                [
                    'Abfallkalender',
                    '#ffc107',
                    $settings['waste_calendar_ics_url'] ?? null,
                    $settings['waste_calendar_ics_filename'] ?? null,
                    $settings['waste_calendar_last_synced_at'] ?? null,
                    $settings['waste_calendar_last_sync_count'] ?? null,
                ]
            );
            $calendarId = (int) $this->connection->lastInsertId();

            $this->connection->executeStatement(
                'INSERT INTO calendar_entry (calendar_id, date, title, ics_uid, confirmed_at)
                 SELECT ?, date, type, ics_uid, confirmed_at FROM waste_collection_date',
                [$calendarId]
            );
        }

        $this->addSql('DROP TABLE waste_collection_date');
        $this->addSql('ALTER TABLE app_settings DROP waste_calendar_ics_url');
        $this->addSql('ALTER TABLE app_settings DROP waste_calendar_ics_filename');
        $this->addSql('ALTER TABLE app_settings DROP waste_calendar_last_synced_at');
        $this->addSql('ALTER TABLE app_settings DROP waste_calendar_last_sync_count');
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement('ALTER TABLE app_settings ADD waste_calendar_ics_url VARCHAR(255) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE app_settings ADD waste_calendar_ics_filename VARCHAR(255) DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE app_settings ADD waste_calendar_last_synced_at DATETIME DEFAULT NULL');
        $this->connection->executeStatement('ALTER TABLE app_settings ADD waste_calendar_last_sync_count INT DEFAULT NULL');

        $this->connection->executeStatement('CREATE TABLE waste_collection_date (
            id INT AUTO_INCREMENT NOT NULL,
            date DATE NOT NULL,
            type VARCHAR(100) NOT NULL,
            ics_uid VARCHAR(255) NOT NULL,
            confirmed_at DATETIME DEFAULT NULL,
            UNIQUE INDEX uniq_waste_collection_ics_uid (ics_uid),
            INDEX idx_waste_collection_date (date),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Best-effort: only restores data that lived under a calendar named
        // "Abfallkalender" - a real rollback of a multi-calendar setup with
        // manually added calendars can't cleanly collapse back into the old
        // single-purpose shape.
        $wasteCalendar = $this->connection->fetchAssociative(
            "SELECT id, ics_url, ics_filename, last_synced_at, last_sync_count FROM calendar WHERE name = 'Abfallkalender' LIMIT 1"
        );

        if (false !== $wasteCalendar) {
            $this->connection->executeStatement(
                'UPDATE app_settings SET waste_calendar_ics_url = ?, waste_calendar_ics_filename = ?, waste_calendar_last_synced_at = ?, waste_calendar_last_sync_count = ?
                 WHERE id = (SELECT id FROM (SELECT id FROM app_settings ORDER BY id ASC LIMIT 1) AS t)',
                [
                    $wasteCalendar['ics_url'],
                    $wasteCalendar['ics_filename'],
                    $wasteCalendar['last_synced_at'],
                    $wasteCalendar['last_sync_count'],
                ]
            );

            $this->connection->executeStatement(
                'INSERT INTO waste_collection_date (date, type, ics_uid, confirmed_at)
                 SELECT date, title, ics_uid, confirmed_at FROM calendar_entry WHERE calendar_id = ?',
                [$wasteCalendar['id']]
            );
        }

        $this->addSql('DROP TABLE calendar_entry');
        $this->addSql('DROP TABLE calendar');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
