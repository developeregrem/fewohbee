<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replaces the fixed weekday/weekend minimum stay with freely definable booking rules and
 * carries the existing settings over.
 *
 * One deliberate behaviour change: Sunday arrivals move from the weekend value to the
 * weekday value (issue #286). Sunday used to be a weekend arrival, so "4 nights on
 * weekdays, 2 at the weekend" let a Sunday-to-Tuesday stay through even though it occupied
 * a Monday night. Operators can change this afterwards, but the released default was the
 * surprising one, so the migration corrects it. See the 4.12.0 release notes.
 */
final class Version20260907153507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate online minimum stays to category-scoped booking rules and move Sunday arrivals to the weekday value';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE booking_restriction_rule (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(24) NOT NULL, min_nights SMALLINT DEFAULT NULL, weekdays JSON NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, enabled TINYINT NOT NULL, all_categories TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE booking_restriction_rule_category (rule_id INT NOT NULL, category_id INT NOT NULL, INDEX IDX_E2B18F94744E0351 (rule_id), INDEX IDX_E2B18F9412469DE2 (category_id), PRIMARY KEY (rule_id, category_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking_restriction_rule_category ADD CONSTRAINT FK_E2B18F94744E0351 FOREIGN KEY (rule_id) REFERENCES booking_restriction_rule (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE booking_restriction_rule_category ADD CONSTRAINT FK_E2B18F9412469DE2 FOREIGN KEY (category_id) REFERENCES room_category (id) ON DELETE CASCADE');

        foreach ($this->legacyRules() as $rule) {
            $this->addSql('INSERT INTO booking_restriction_rule (type, min_nights, weekdays, start_date, end_date, enabled, all_categories) VALUES (?, ?, ?, ?, ?, 1, ?)', [
                'min_stay_arrival', $rule['min_nights'], json_encode($rule['weekdays'], JSON_THROW_ON_ERROR),
                $rule['start_date'], $rule['end_date'], $rule['all_categories'],
            ]);
            if (null !== $rule['category_id']) {
                $this->addSql('INSERT INTO booking_restriction_rule_category (rule_id, category_id) VALUES (LAST_INSERT_ID(), ?)', [$rule['category_id']]);
            }
        }
    }

    /** Refuses to discard changes which cannot be restored from the retained legacy settings. */
    public function down(Schema $schema): void
    {
        $actual = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM booking_restriction_rule') as $row) {
            $categories = $this->connection->fetchFirstColumn('SELECT category_id FROM booking_restriction_rule_category WHERE rule_id = ? ORDER BY category_id', [$row['id']]);
            $this->abortIf('min_stay_arrival' !== $row['type'] || 1 !== (int) $row['enabled'] || count($categories) > 1,
                'Booking rules have changed. Export the rules and restore the pre-upgrade backup before downgrading.');
            $actual[] = json_encode([
                'min_nights' => (int) $row['min_nights'],
                'weekdays' => json_decode((string) $row['weekdays'], true, flags: JSON_THROW_ON_ERROR),
                'start_date' => $row['start_date'], 'end_date' => $row['end_date'],
                'all_categories' => (int) $row['all_categories'], 'category_id' => [] === $categories ? null : (int) $categories[0],
            ], JSON_THROW_ON_ERROR);
        }
        $expected = array_map(static fn (array $rule): string => json_encode($rule, JSON_THROW_ON_ERROR), $this->legacyRules());
        sort($actual);
        sort($expected);
        $this->abortIf($actual !== $expected,
            'Booking rules have changed. Export the rules and restore the pre-upgrade backup before downgrading.');
        $this->addSql('DROP TABLE booking_restriction_rule_category');
        $this->addSql('DROP TABLE booking_restriction_rule');
    }

    /**
     * The legacy settings expressed as booking rules, and the single source of truth for
     * both up() and the down() guard.
     *
     * Sunday joins the weekday rule — but only when a weekday value exists. Without one,
     * moving Sunday out of the weekend rule would leave it unrestricted, so the migration
     * would *loosen* an existing booking condition. It never does that: it either keeps a
     * restriction or tightens it.
     *
     * @return list<array{min_nights: int, weekdays: list<int>, start_date: ?string, end_date: ?string, all_categories: int, category_id: ?int}>
     */
    private function legacyRules(): array
    {
        $rules = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM online_booking_min_stay ORDER BY id') as $row) {
            $hasWeekday = null !== $row['min_nights_weekday'];
            $groups = [
                'min_nights_weekday' => $hasWeekday ? [1, 2, 3, 4, 7] : [1, 2, 3, 4],
                'min_nights_weekend' => $hasWeekday ? [5, 6] : [5, 6, 7],
            ];

            foreach ($groups as $column => $days) {
                if (null === $row[$column]) {
                    continue;
                }
                $rules[] = [
                    'min_nights' => (int) $row[$column], 'weekdays' => $days,
                    'start_date' => null, 'end_date' => null, 'all_categories' => 0, 'category_id' => (int) $row['room_category_id'],
                ];
            }
        }

        // Overrides already stored an exclusive end date and always applied to every weekday.
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM online_booking_min_stay_override ORDER BY id') as $row) {
            $rules[] = [
                'min_nights' => (int) $row['min_nights'], 'weekdays' => [1, 2, 3, 4, 5, 6, 7],
                'start_date' => $row['start_date'], 'end_date' => $row['end_date'],
                'all_categories' => null === $row['room_category_id'] ? 1 : 0,
                'category_id' => null === $row['room_category_id'] ? null : (int) $row['room_category_id'],
            ];
        }

        return $rules;
    }
}
