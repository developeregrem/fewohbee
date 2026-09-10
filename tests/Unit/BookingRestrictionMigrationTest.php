<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\MySQLSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use DoctrineMigrations\Version20260907153507;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20260907153507.php';

/**
 * Guards the one deliberate behaviour change of the migration (Sunday moves to the weekday
 * value), the promise that it never loosens a restriction, and the refusal of a lossy
 * downgrade.
 */
final class BookingRestrictionMigrationTest extends TestCase
{
    public function testSundayMovesToTheWeekdayRuleAndPeriodEndStaysExclusive(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'online_booking_min_stay_override')
            ? [['id' => 9, 'room_category_id' => null, 'min_nights' => 7, 'start_date' => '2026-09-13', 'end_date' => '2026-09-15']]
            : [['id' => 3, 'room_category_id' => 5, 'min_nights_weekday' => 4, 'min_nights_weekend' => 2]]);

        $migration = new Version20260907153507($connection, new NullLogger());
        $migration->up(new Schema());

        $imports = [];
        foreach ($migration->getSql() as $query) {
            self::assertStringNotContainsString('DROP ', $query->getStatement());
            if (str_starts_with($query->getStatement(), 'INSERT INTO booking_restriction_rule (')) {
                $imports[] = $query->getParameters();
            }
        }

        self::assertSame([
            // Sunday (7) now belongs to the weekday value — the fix for issue #286.
            ['min_stay_arrival', 4, '[1,2,3,4,7]', null, null, 0],
            ['min_stay_arrival', 2, '[5,6]', null, null, 0],
            ['min_stay_arrival', 7, '[1,2,3,4,5,6,7]', '2026-09-13', '2026-09-15', 1],
        ], $imports);
    }

    public function testSundayStaysWithTheWeekendValueWhenNoWeekdayValueExists(): void
    {
        // Moving Sunday out would leave it unrestricted, so the migration would loosen a
        // booking condition. It must never do that.
        $connection = $this->connection();
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'online_booking_min_stay_override')
            ? []
            : [['id' => 3, 'room_category_id' => 5, 'min_nights_weekday' => null, 'min_nights_weekend' => 3]]);

        $migration = new Version20260907153507($connection, new NullLogger());
        $migration->up(new Schema());

        $imports = [];
        foreach ($migration->getSql() as $query) {
            if (str_starts_with($query->getStatement(), 'INSERT INTO booking_restriction_rule (')) {
                $imports[] = $query->getParameters();
            }
        }

        self::assertSame([['min_stay_arrival', 3, '[5,6,7]', null, null, 0]], $imports);
    }

    public function testUnchangedRulesCanBeRolledBackWithoutDeletingLegacyTables(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'SELECT * FROM booking_restriction_rule')) {
                return [['id' => 1, 'type' => 'min_stay_arrival', 'min_nights' => 4, 'weekdays' => '[1,2,3,4,7]', 'start_date' => null, 'end_date' => null, 'enabled' => 1, 'all_categories' => 0]];
            }

            return str_contains($sql, 'online_booking_min_stay_override')
                ? []
                : [['id' => 3, 'room_category_id' => 5, 'min_nights_weekday' => 4, 'min_nights_weekend' => null]];
        });
        $connection->method('fetchFirstColumn')->willReturn([5]);

        $migration = new Version20260907153507($connection, new NullLogger());
        $migration->down(new Schema());

        self::assertSame(
            ['DROP TABLE booking_restriction_rule_category', 'DROP TABLE booking_restriction_rule'],
            array_map(strval(...), $migration->getSql()),
        );
    }

    public function testDowngradeRefusesToDiscardNewNightRules(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAllAssociative')->willReturn([['id' => 1, 'type' => 'min_stay_through']]);
        $connection->method('fetchFirstColumn')->willReturn([]);

        $migration = new Version20260907153507($connection, new NullLogger());
        $this->expectException(AbortMigration::class);
        $migration->down(new Schema());
    }

    public function testDowngradeRefusesToDiscardClosures(): void
    {
        $connection = $this->connection();
        $connection->method('fetchAllAssociative')->willReturn([['id' => 1, 'type' => 'closed_to_arrival']]);
        $connection->method('fetchFirstColumn')->willReturn([]);

        $migration = new Version20260907153507($connection, new NullLogger());
        $this->expectException(AbortMigration::class);
        $migration->down(new Schema());
    }

    private function connection(): Connection&Stub
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(MySQLSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());

        return $connection;
    }
}
