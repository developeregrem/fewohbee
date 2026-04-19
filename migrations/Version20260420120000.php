<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260420120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_bank_account flag to accounting_accounts and mark preset bank accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounting_accounts ADD is_bank_account TINYINT(1) DEFAULT 0 NOT NULL');

        // Mark bank accounts of the known presets (SKR03, SKR04, EKR AT, KMU CH)
        $this->addSql("UPDATE accounting_accounts SET is_bank_account = 1 WHERE account_number IN ('1200', '1800', '2800', '1020')");

        // Add is_opening_balance_account flag + seed opening balance accounts per preset
        $this->connection->executeStatement('ALTER TABLE accounting_accounts ADD is_opening_balance_account TINYINT(1) DEFAULT 0 NOT NULL');

        // Seed the opening balance account for the active preset (if any settings row exists).
        $preset = $this->connection->fetchOne('SELECT chart_preset FROM accounting_settings LIMIT 1');

        if (false === $preset || null === $preset) {
            return;
        }

        [$number, $name, $type] = match ($preset) {
            'skr03', 'skr04' => ['9000', 'Saldenvorträge Sachkonten', 'liability'],
            'ekr_at'         => ['9800', 'Eröffnungsbilanz', 'liability'],
            'kmu_ch'         => ['9100', 'Eröffnungsbilanz', 'liability'],
            default          => [null, null, null],
        };

        if (null === $number) {
            return;
        }

        // Insert only if no opening-balance account exists yet.
        $existing = $this->connection->fetchOne(
            'SELECT id FROM accounting_accounts WHERE is_opening_balance_account = 1 LIMIT 1'
        );
        if (false !== $existing && null !== $existing) {
            return;
        }

        // If the account number is already taken (user customization), flip the flag on it.
        $existingByNumber = $this->connection->fetchOne(
            'SELECT id FROM accounting_accounts WHERE account_number = :num LIMIT 1',
            ['num' => $number]
        );

        if (false !== $existingByNumber && null !== $existingByNumber) {
            $this->addSql(
                'UPDATE accounting_accounts SET is_opening_balance_account = 1 WHERE id = :id',
                ['id' => $existingByNumber]
            );

            return;
        }

        $this->addSql(
            'INSERT INTO accounting_accounts (account_number, name, type, is_cash_account, is_bank_account, is_opening_balance_account, is_system_default, sort_order, created_at)
             VALUES (:num, :name, :type, 0, 0, 1, 1, 9999, NOW())',
            ['num' => $number, 'name' => $name, 'type' => $type]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounting_accounts DROP is_bank_account');

        $this->addSql('ALTER TABLE accounting_accounts DROP is_opening_balance_account');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
