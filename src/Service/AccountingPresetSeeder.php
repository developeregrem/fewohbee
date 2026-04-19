<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccountingAccount;
use App\Entity\AccountingSettings;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Enum\PaymentMeansCode;
use App\Entity\TaxRate;
use App\Entity\Workflow;
use App\Repository\AccountingAccountRepository;
use App\Repository\WorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Seeds accounting accounts and tax rates from predefined chart-of-accounts presets.
 *
 * Idempotent: only inserts accounts/rates that don't already exist (by accountNumber/rate).
 */
class AccountingPresetSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly WorkflowRepository $workflowRepo,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Seed accounts for the given preset. Existing accounts (by accountNumber) are skipped.
     *
     * @return int number of accounts created
     */
    public function seedAccounts(string $preset): int
    {
        $definitions = $this->getAccountDefinitions($preset);
        $created = 0;

        foreach ($definitions as $i => $def) {
            if (null !== $this->accountRepo->findByNumber($def['number'])) {
                continue;
            }

            $account = new AccountingAccount();
            $account->setAccountNumber($def['number']);
            $account->setName($this->translator->trans($def['nameKey']));
            $account->setType($def['type']);
            $account->setIsCashAccount($def['isCash'] ?? false);
            $account->setIsBankAccount($def['isBank'] ?? false);
            $account->setIsOpeningBalanceAccount($def['isOpening'] ?? false);
            $account->setIsSystemDefault(true);
            $account->setSortOrder($i * 10);

            $this->em->persist($account);
            ++$created;
        }

        $this->em->flush();

        return $created;
    }

    /**
     * Seed tax rates for the given preset. Existing rates (by exact rate value) are skipped.
     *
     * @return int number of tax rates created
     */
    public function seedTaxRates(string $preset): int
    {
        $definitions = $this->getTaxRateDefinitions($preset);
        $existing = $this->em->getRepository(TaxRate::class)->findAll();
        $existingRates = array_map(fn (TaxRate $t) => $t->getRate(), $existing);

        $created = 0;
        foreach ($definitions as $i => $def) {
            if (in_array(number_format((float) $def['rate'], 2, '.', ''), $existingRates, true)) {
                continue;
            }

            $taxRate = new TaxRate();
            $taxRate->setName($this->translator->trans($def['nameKey']));
            $taxRate->setRate(number_format((float) $def['rate'], 2, '.', ''));
            $taxRate->setDatevBuKey($def['buKey'] ?? null);
            $taxRate->setIsDefault($def['isDefault'] ?? false);
            $taxRate->setSortOrder($i * 10);

            if (!empty($def['revenueAccountNumber'])) {
                $account = $this->accountRepo->findByNumber($def['revenueAccountNumber']);
                if ($account !== null) {
                    $taxRate->setRevenueAccount($account);
                }
            }

            $this->em->persist($taxRate);
            ++$created;
        }

        $this->em->flush();

        return $created;
    }

    /**
     * @return array<int, array{number: string, nameKey: string, type: string, isCash?: bool}>
     */
    private function getAccountDefinitions(string $preset): array
    {
        return match ($preset) {
            AccountingSettings::PRESET_SKR03 => $this->getSkr03Accounts(),
            AccountingSettings::PRESET_SKR04 => $this->getSkr04Accounts(),
            AccountingSettings::PRESET_EKR_AT => $this->getEkrAtAccounts(),
            AccountingSettings::PRESET_KMU_CH => $this->getKmuChAccounts(),
            default => [],
        };
    }

    /**
     * @return array<int, array{nameKey: string, rate: float, buKey?: string, isDefault?: bool, revenueAccountNumber?: string}>
     */
    private function getTaxRateDefinitions(string $preset): array
    {
        return match ($preset) {
            AccountingSettings::PRESET_SKR03 => $this->getSkr03TaxRates(),
            AccountingSettings::PRESET_SKR04 => $this->getSkr04TaxRates(),
            AccountingSettings::PRESET_EKR_AT => $this->getAtTaxRates(),
            AccountingSettings::PRESET_KMU_CH => $this->getChTaxRates(),
            default => [],
        };
    }

    // ── SKR03 (Germany) ──────────────────────────────────────────────

    private function getSkr03Accounts(): array
    {
        return [
            ['number' => '1000', 'nameKey' => 'preset.account.cash', 'type' => AccountingAccount::TYPE_ASSET, 'isCash' => true],
            ['number' => '1200', 'nameKey' => 'preset.account.bank', 'type' => AccountingAccount::TYPE_ASSET, 'isBank' => true],
            ['number' => '1400', 'nameKey' => 'preset.account.receivables', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1450', 'nameKey' => 'preset.account.receivables_credit_card', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1570', 'nameKey' => 'preset.account.input_tax_reduced', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1576', 'nameKey' => 'preset.account.input_tax_standard', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1770', 'nameKey' => 'preset.account.vat_reduced', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '1776', 'nameKey' => 'preset.account.vat_standard', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '1590', 'nameKey' => 'preset.account.transit', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '8300', 'nameKey' => 'preset.account.revenue_accommodation', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '8400', 'nameKey' => 'preset.account.revenue_standard', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '8100', 'nameKey' => 'preset.account.revenue_tax_free', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '8720', 'nameKey' => 'preset.account.revenue_deductions', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4930', 'nameKey' => 'preset.account.office_supplies', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '4980', 'nameKey' => 'preset.account.other_expenses', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '6300', 'nameKey' => 'preset.account.other_operating_expenses', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '4200', 'nameKey' => 'preset.account.premises', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '4360', 'nameKey' => 'preset.account.cleaning', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '9000', 'nameKey' => 'preset.account.opening_balance', 'type' => AccountingAccount::TYPE_LIABILITY, 'isOpening' => true],
        ];
    }

    // ── SKR04 (Germany) ──────────────────────────────────────────────

    private function getSkr04Accounts(): array
    {
        return [
            ['number' => '1600', 'nameKey' => 'preset.account.cash', 'type' => AccountingAccount::TYPE_ASSET, 'isCash' => true],
            ['number' => '1800', 'nameKey' => 'preset.account.bank', 'type' => AccountingAccount::TYPE_ASSET, 'isBank' => true],
            ['number' => '1200', 'nameKey' => 'preset.account.receivables', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1210', 'nameKey' => 'preset.account.receivables_credit_card', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1400', 'nameKey' => 'preset.account.input_tax_reduced', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1406', 'nameKey' => 'preset.account.input_tax_standard', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '3800', 'nameKey' => 'preset.account.vat_reduced', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '3806', 'nameKey' => 'preset.account.vat_standard', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '1590', 'nameKey' => 'preset.account.transit', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '4300', 'nameKey' => 'preset.account.revenue_accommodation', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4400', 'nameKey' => 'preset.account.revenue_standard', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4100', 'nameKey' => 'preset.account.revenue_tax_free', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4740', 'nameKey' => 'preset.account.revenue_deductions', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '6815', 'nameKey' => 'preset.account.office_supplies', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '6300', 'nameKey' => 'preset.account.other_operating_expenses', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '6310', 'nameKey' => 'preset.account.premises', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '6330', 'nameKey' => 'preset.account.cleaning', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '9000', 'nameKey' => 'preset.account.opening_balance', 'type' => AccountingAccount::TYPE_LIABILITY, 'isOpening' => true],
        ];
    }

    // ── EKR (Austria) ────────────────────────────────────────────────

    private function getEkrAtAccounts(): array
    {
        return [
            ['number' => '2700', 'nameKey' => 'preset.account.cash_at', 'type' => AccountingAccount::TYPE_ASSET, 'isCash' => true],
            ['number' => '2800', 'nameKey' => 'preset.account.bank', 'type' => AccountingAccount::TYPE_ASSET, 'isBank' => true],
            ['number' => '2000', 'nameKey' => 'preset.account.receivables', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '2500', 'nameKey' => 'preset.account.input_tax', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '3500', 'nameKey' => 'preset.account.vat', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '3790', 'nameKey' => 'preset.account.transit', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '4000', 'nameKey' => 'preset.account.revenue_accommodation_10', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4010', 'nameKey' => 'preset.account.revenue_accommodation_13', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4020', 'nameKey' => 'preset.account.revenue_20', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '4090', 'nameKey' => 'preset.account.revenue_tax_free', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '7600', 'nameKey' => 'preset.account.office_supplies', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '7100', 'nameKey' => 'preset.account.other_operating_expenses', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '7200', 'nameKey' => 'preset.account.maintenance', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '7300', 'nameKey' => 'preset.account.cleaning', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '9800', 'nameKey' => 'preset.account.opening_balance', 'type' => AccountingAccount::TYPE_LIABILITY, 'isOpening' => true],
        ];
    }

    // ── KMU-Kontenrahmen (Switzerland) ───────────────────────────────

    private function getKmuChAccounts(): array
    {
        return [
            ['number' => '1000', 'nameKey' => 'preset.account.cash', 'type' => AccountingAccount::TYPE_ASSET, 'isCash' => true],
            ['number' => '1020', 'nameKey' => 'preset.account.bank', 'type' => AccountingAccount::TYPE_ASSET, 'isBank' => true],
            ['number' => '1100', 'nameKey' => 'preset.account.receivables', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '1170', 'nameKey' => 'preset.account.input_tax', 'type' => AccountingAccount::TYPE_ASSET],
            ['number' => '2200', 'nameKey' => 'preset.account.vat_ch', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '2030', 'nameKey' => 'preset.account.transit', 'type' => AccountingAccount::TYPE_LIABILITY],
            ['number' => '3200', 'nameKey' => 'preset.account.revenue_accommodation_ch', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '3400', 'nameKey' => 'preset.account.revenue_standard_ch', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '3000', 'nameKey' => 'preset.account.revenue_tax_free', 'type' => AccountingAccount::TYPE_REVENUE],
            ['number' => '6500', 'nameKey' => 'preset.account.office_supplies', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '6000', 'nameKey' => 'preset.account.other_expenses', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '6100', 'nameKey' => 'preset.account.maintenance_cleaning', 'type' => AccountingAccount::TYPE_EXPENSE],
            ['number' => '9100', 'nameKey' => 'preset.account.opening_balance', 'type' => AccountingAccount::TYPE_LIABILITY, 'isOpening' => true],
        ];
    }

    // ── Workflow presets ─────────────────────────────────────────────

    /**
     * Seed example workflows for the given preset. Existing workflows (by systemCode) are skipped.
     *
     * @return int number of workflows created
     */
    public function seedWorkflows(string $preset): int
    {
        $definitions = $this->getWorkflowDefinitions($preset);
        $created = 0;

        foreach ($definitions as $def) {
            if (null !== $this->workflowRepo->findBySystemCode($def['systemCode'])) {
                continue;
            }

            // Resolve debit account by number
            $debitAccount = $this->accountRepo->findByNumber($def['debitAccountNumber']);
            if (null === $debitAccount) {
                continue; // account not found, skip this workflow
            }

            $workflow = new Workflow();
            $workflow->setName($this->translator->trans($def['nameKey']));
            $workflow->setDescription($this->translator->trans($def['descriptionKey']));
            $workflow->setSystemCode($def['systemCode']);
            $workflow->setIsEnabled(true);
            $workflow->setTriggerType('invoice.status_changed');
            $workflow->setConditions($def['conditions']);
            $workflow->setActionType($def['actionType']);
            $workflow->setActionConfig($this->buildActionConfig($def, $debitAccount));
            $workflow->setPriority($def['priority'] ?? 0);

            $this->em->persist($workflow);
            ++$created;
        }

        $this->em->flush();

        return $created;
    }

    private function buildActionConfig(array $def, AccountingAccount $debitAccount): array
    {
        return [
            'debitAccountId' => $debitAccount->getId(),
            'fallbackCreditAccountId' => '',
            'remark' => '',
        ];
    }

    /**
     * @return list<array{systemCode: string, nameKey: string, descriptionKey: string, actionType: string, debitAccountNumber: string, conditions: array, priority?: int}>
     */
    private function getWorkflowDefinitions(string $preset): array
    {
        return match ($preset) {
            AccountingSettings::PRESET_SKR03 => $this->getWorkflows('skr03', '1000', '1200', '1450'),
            AccountingSettings::PRESET_SKR04 => $this->getWorkflows('skr04', '1600', '1800', '1210'),
            AccountingSettings::PRESET_EKR_AT => $this->getWorkflows('ekr_at', '2700', '2800', '2000'),
            AccountingSettings::PRESET_KMU_CH => $this->getWorkflows('kmu_ch', '1000', '1020', '1100'),
            default => [],
        };
    }

    /**
     * @return list<array>
     */
    private function getWorkflows(string $presetKey, string $cashNumber, string $bankNumber, string $cardNumber): array
    {
        $paidCondition = [
            'type' => 'invoice.status_is',
            'config' => ['status' => InvoiceStatus::PAID->value],
        ];

        return [
            [
                'systemCode' => "preset_{$presetKey}_booking_cash",
                'nameKey' => 'preset.workflow.booking_cash',
                'descriptionKey' => 'preset.workflow.booking_cash.desc',
                'actionType' => 'create_booking_entry',
                'debitAccountNumber' => $cashNumber,
                'conditions' => [
                    $paidCondition,
                    ['type' => 'invoice.payment_means_is', 'config' => ['paymentMeansCode' => PaymentMeansCode::CASH->value]],
                ],
                'priority' => 5,
            ],
            [
                'systemCode' => "preset_{$presetKey}_booking_transfer",
                'nameKey' => 'preset.workflow.booking_transfer',
                'descriptionKey' => 'preset.workflow.booking_transfer.desc',
                'actionType' => 'create_booking_entry',
                'debitAccountNumber' => $bankNumber,
                'conditions' => [
                    $paidCondition,
                    ['type' => 'invoice.payment_means_is', 'config' => ['paymentMeansCode' => PaymentMeansCode::SEPA_CREDIT_TRANSFER->value]],
                ],
                'priority' => 5,
            ],
            [
                'systemCode' => "preset_{$presetKey}_booking_card",
                'nameKey' => 'preset.workflow.booking_card',
                'descriptionKey' => 'preset.workflow.booking_card.desc',
                'actionType' => 'create_booking_entry',
                'debitAccountNumber' => $cardNumber,
                'conditions' => [
                    $paidCondition,
                    ['type' => 'invoice.payment_means_is', 'config' => ['paymentMeansCode' => PaymentMeansCode::CARD_PAYMENT->value]],
                ],
                'priority' => 5,
            ],
        ];
    }

    // ── Tax rates ────────────────────────────────────────────────────

    private function getSkr03TaxRates(): array
    {
        return [
            ['nameKey' => 'preset.taxrate.tax_free', 'rate' => 0.00, 'buKey' => null,  'isDefault' => false, 'revenueAccountNumber' => '8100'],
            ['nameKey' => 'preset.taxrate.de_7',     'rate' => 7.00, 'buKey' => '2',   'isDefault' => false, 'revenueAccountNumber' => '8300'],
            ['nameKey' => 'preset.taxrate.de_19',    'rate' => 19.00, 'buKey' => '3',  'isDefault' => true,  'revenueAccountNumber' => '8400'],
        ];
    }

    private function getSkr04TaxRates(): array
    {
        return [
            ['nameKey' => 'preset.taxrate.tax_free', 'rate' => 0.00, 'buKey' => null,  'isDefault' => false, 'revenueAccountNumber' => '4100'],
            ['nameKey' => 'preset.taxrate.de_7',     'rate' => 7.00, 'buKey' => '2',   'isDefault' => false, 'revenueAccountNumber' => '4300'],
            ['nameKey' => 'preset.taxrate.de_19',    'rate' => 19.00, 'buKey' => '3',  'isDefault' => true,  'revenueAccountNumber' => '4400'],
        ];
    }

    private function getAtTaxRates(): array
    {
        return [
            ['nameKey' => 'preset.taxrate.tax_free', 'rate' => 0.00,  'buKey' => null, 'isDefault' => false, 'revenueAccountNumber' => '4090'],
            ['nameKey' => 'preset.taxrate.at_10',    'rate' => 10.00, 'buKey' => null, 'isDefault' => false, 'revenueAccountNumber' => '4000'],
            ['nameKey' => 'preset.taxrate.at_13',    'rate' => 13.00, 'buKey' => null, 'isDefault' => false, 'revenueAccountNumber' => '4010'],
            ['nameKey' => 'preset.taxrate.at_20',    'rate' => 20.00, 'buKey' => null, 'isDefault' => true,  'revenueAccountNumber' => '4020'],
        ];
    }

    private function getChTaxRates(): array
    {
        return [
            ['nameKey' => 'preset.taxrate.tax_free', 'rate' => 0.00, 'buKey' => null, 'isDefault' => false, 'revenueAccountNumber' => '3000'],
            ['nameKey' => 'preset.taxrate.ch_2_6',   'rate' => 2.60, 'buKey' => null, 'isDefault' => false, 'revenueAccountNumber' => '3200'],
            ['nameKey' => 'preset.taxrate.ch_3_8',   'rate' => 3.80, 'buKey' => null, 'isDefault' => false, 'revenueAccountNumber' => '3200'],
            ['nameKey' => 'preset.taxrate.ch_8_1',   'rate' => 8.10, 'buKey' => null, 'isDefault' => true,  'revenueAccountNumber' => '3400'],
        ];
    }
}
