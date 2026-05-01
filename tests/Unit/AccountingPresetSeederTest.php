<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Workflow;
use App\Repository\AccountingAccountRepository;
use App\Repository\WorkflowRepository;
use App\Service\BookingJournal\AccountingPresetSeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AccountingPresetSeederTest extends TestCase
{
    // ── seedWorkflows ───────────────────────────────────────────────

    public function testSeedWorkflowsCreatesExpectedWorkflowsForSkr03(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumber')->willReturnCallback(function (string $number) {
            return $this->makeAccount($number);
        });

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findBySystemCode')->willReturn(null);

        $seeder = $this->createSeeder($em, $accountRepo, $workflowRepo);

        $count = $seeder->seedWorkflows(AccountingSettings::PRESET_SKR03);

        self::assertSame(3, $count);
        self::assertCount(3, $persisted);

        // All workflows use create_booking_entry
        $actionTypes = array_map(fn (Workflow $w) => $w->getActionType(), $persisted);
        self::assertCount(3, array_filter($actionTypes, fn ($t) => 'create_booking_entry' === $t));
    }

    public function testSeedWorkflowsSkipsExistingBySystemCode(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumber')->willReturnCallback(fn (string $n) => $this->makeAccount($n));

        // All system codes already exist
        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findBySystemCode')->willReturn(new Workflow());

        $seeder = $this->createSeeder($em, $accountRepo, $workflowRepo);

        $count = $seeder->seedWorkflows(AccountingSettings::PRESET_SKR03);

        self::assertSame(0, $count);
    }

    public function testSeedWorkflowsSkipsWhenAccountNotFound(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumber')->willReturn(null);

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findBySystemCode')->willReturn(null);

        $seeder = $this->createSeeder($em, $accountRepo, $workflowRepo);

        $count = $seeder->seedWorkflows(AccountingSettings::PRESET_SKR03);

        self::assertSame(0, $count);
    }

    public function testSeedWorkflowsHasCorrectConditions(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumber')->willReturnCallback(fn (string $n) => $this->makeAccount($n));

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findBySystemCode')->willReturn(null);

        $seeder = $this->createSeeder($em, $accountRepo, $workflowRepo);
        $seeder->seedWorkflows(AccountingSettings::PRESET_SKR03);

        // All workflows should have invoice.status_changed trigger
        foreach ($persisted as $workflow) {
            self::assertSame('invoice.status_changed', $workflow->getTriggerType());
        }

        // Each workflow should have exactly 2 conditions (status + payment means)
        foreach ($persisted as $workflow) {
            $conditions = $workflow->getConditions();
            self::assertCount(2, $conditions);
            self::assertSame('invoice.status_is', $conditions[0]['type']);
            self::assertSame(InvoiceStatus::PAID->value, $conditions[0]['config']['status']);
            self::assertSame('invoice.payment_means_is', $conditions[1]['type']);
        }

        // Verify payment means codes per workflow
        $systemCodes = [];
        foreach ($persisted as $workflow) {
            $paymentCode = $workflow->getConditions()[1]['config']['paymentMeansCode'];
            $systemCodes[$workflow->getSystemCode()] = $paymentCode;
        }

        self::assertSame(PaymentMeansCode::CASH->value, $systemCodes['preset_skr03_booking_cash']);
        self::assertSame(PaymentMeansCode::SEPA_CREDIT_TRANSFER->value, $systemCodes['preset_skr03_booking_transfer']);
        self::assertSame(PaymentMeansCode::CARD_PAYMENT->value, $systemCodes['preset_skr03_booking_card']);
    }

    #[DataProvider('presetProvider')]
    public function testSeedWorkflowsCreatesWorkflowsForAllPresets(string $preset): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumber')->willReturnCallback(fn (string $n) => $this->makeAccount($n));

        $workflowRepo = $this->createStub(WorkflowRepository::class);
        $workflowRepo->method('findBySystemCode')->willReturn(null);

        $seeder = $this->createSeeder($em, $accountRepo, $workflowRepo);
        $count = $seeder->seedWorkflows($preset);

        self::assertSame(3, $count, "Expected 3 workflows for preset {$preset}");
    }

    public static function presetProvider(): \Generator
    {
        yield 'skr03' => [AccountingSettings::PRESET_SKR03];
        yield 'skr04' => [AccountingSettings::PRESET_SKR04];
        yield 'ekr_at' => [AccountingSettings::PRESET_EKR_AT];
        yield 'kmu_ch' => [AccountingSettings::PRESET_KMU_CH];
    }

    public function testSeedWorkflowsReturnsZeroForInvalidPreset(): void
    {
        $seeder = $this->createSeeder();

        self::assertSame(0, $seeder->seedWorkflows('invalid_preset'));
    }

    // ── seedAccounts ────────────────────────────────────────────────

    #[DataProvider('presetProvider')]
    public function testSeedAccountsCreatesAccountsForAllPresets(string $preset): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumber')->willReturn(null);

        $seeder = $this->createSeeder($em, $accountRepo);
        $count = $seeder->seedAccounts($preset);

        self::assertGreaterThan(0, $count);
        self::assertSame($count, count($persisted));

        // At least one cash account
        $cashAccounts = array_filter($persisted, fn (AccountingAccount $a) => $a->isCashAccount());
        self::assertNotEmpty($cashAccounts, "Preset {$preset} must have a cash account");

        // All accounts are system defaults
        foreach ($persisted as $account) {
            self::assertTrue($account->isSystemDefault());
        }
    }

    public function testSeedAccountsSkipsExisting(): void
    {
        $accountRepo = $this->createStub(AccountingAccountRepository::class);
        $accountRepo->method('findByNumberAndPreset')->willReturn(new AccountingAccount());

        $seeder = $this->createSeeder(accountRepo: $accountRepo);
        $count = $seeder->seedAccounts(AccountingSettings::PRESET_SKR03);

        self::assertSame(0, $count);
    }

    // ── seedTaxRates ────────────────────────────────────────────────

    #[DataProvider('presetProvider')]
    public function testSeedTaxRatesCreatesRatesForAllPresets(string $preset): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted) {
            $persisted[] = $entity;
        });
        $em->method('getRepository')->willReturnCallback(function () {
            $repo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
            $repo->method('findAll')->willReturn([]);

            return $repo;
        });

        $seeder = $this->createSeeder($em);
        $count = $seeder->seedTaxRates($preset);

        self::assertGreaterThan(0, $count);
        self::assertSame($count, count($persisted));

        // Exactly one default rate
        $defaults = array_filter($persisted, fn ($t) => $t->isDefault());
        self::assertCount(1, $defaults, "Preset {$preset} must have exactly one default tax rate");
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createSeeder(
        ?EntityManagerInterface $em = null,
        ?AccountingAccountRepository $accountRepo = null,
        ?WorkflowRepository $workflowRepo = null,
    ): AccountingPresetSeeder {
        $em ??= $this->createStub(EntityManagerInterface::class);
        $accountRepo ??= $this->createStub(AccountingAccountRepository::class);
        $workflowRepo ??= $this->createStub(WorkflowRepository::class);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AccountingPresetSeeder($em, $accountRepo, $workflowRepo, $translator);
    }

    private function makeAccount(string $number): AccountingAccount
    {
        $account = new AccountingAccount();
        $account->setAccountNumber($number);
        $account->setName('Account '.$number);

        return $account;
    }
}
