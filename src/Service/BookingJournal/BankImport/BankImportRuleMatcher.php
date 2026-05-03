<?php

declare(strict_types=1);

namespace App\Service\BookingJournal\BankImport;

use App\Dto\BookingJournal\BankImport\ImportState;
use App\Entity\AccountingAccount;
use App\Entity\BankImportRule;
use App\Repository\BankImportRuleRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Walks the user's rule templates against every statement line in the draft
 * and applies the first matching rule (priority DESC, short-circuit on first
 * match — exactly like the Workflow engine).
 *
 * Lines that are already duplicates or have been explicitly ignored are
 * skipped so we never overwrite stronger signals.
 */
final class BankImportRuleMatcher
{
    public function __construct(
        private readonly BankImportRuleRepository $ruleRepo,
        private readonly RuleConditionEvaluator $evaluator,
        private readonly RuleActionApplicator $applicator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function annotate(ImportState $state, AccountingAccount $bankAccount): void
    {
        $rules = $this->ruleRepo->findActiveForAccount($bankAccount);
        if ([] === $rules) {
            return;
        }

        foreach ($state->lines as &$line) {
            if (true === ($line['isDuplicate'] ?? false)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (!$this->ruleMatches($rule, $line)) {
                    continue;
                }

                $this->applicator->apply($rule, $line);
                if ($this->hasRuleWarning($line['ruleWarning'] ?? null)) {
                    $warning = $this->translator->trans('accounting.bank_import.rule.warning.line', [
                        '%line%' => ((int) ($line['idx'] ?? 0)) + 1,
                        '%message%' => $this->formatRuleWarning($line['ruleWarning']),
                    ]);
                    if (!in_array($warning, $state->warnings, true)) {
                        $state->warnings[] = $warning;
                    }
                }
                break; // first matching rule wins.
            }
        }
        unset($line);
    }

    /**
     * All conditions must match (AND logic). An empty rule matches everything —
     * useful as a catch-all default at low priority.
     *
     * @param array<string, mixed> $line
     */
    private function ruleMatches(BankImportRule $rule, array $line): bool
    {
        foreach ($rule->getConditions() as $condition) {
            if (!$this->evaluator->matches($condition, $line)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $warning
     */
    private function hasRuleWarning(mixed $warning): bool
    {
        if (is_array($warning)) {
            return isset($warning['key']) && '' !== (string) $warning['key'];
        }

        return null !== $warning && '' !== (string) $warning;
    }

    /**
     * @param mixed $warning
     */
    private function formatRuleWarning(mixed $warning): string
    {
        if (is_array($warning) && isset($warning['key'])) {
            $params = $warning['params'] ?? [];

            return $this->translator->trans((string) $warning['key'], is_array($params) ? $params : []);
        }

        return (string) $warning;
    }
}
