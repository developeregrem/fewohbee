<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Entity\BankImportRule;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Edits an existing {@see BankImportRule}: its metadata plus the values its
 * conditions and its action already carry — a misspelled counterparty, the
 * wrong contra account, a marker that needs a fix.
 *
 * The structure stays as the import preview recorded it. Adding a condition,
 * switching the action mode or changing how a split row finds its amount needs
 * the context of a real bank line, so it keeps happening in the preview's
 * "save as rule" dialog.
 */
class BankImportRuleType extends AbstractType
{
    public function __construct(
        private readonly AccountingSettingsService $settingsService,
        private readonly AccountingAccountRepository $accountRepo,
        private readonly TaxRateRepository $taxRateRepo,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $activePreset = $this->settingsService->getActivePreset();
        $rule = $options['data'] instanceof BankImportRule ? $options['data'] : new BankImportRule();
        $accountChoices = $this->accountChoices($rule, $activePreset);
        $taxRateChoices = $this->taxRateChoices($rule, $activePreset);

        $builder
            ->add('name', TextType::class, [
                'label' => 'accounting.bank_import.rules.field.name',
                'attr' => ['maxlength' => 150],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'accounting.bank_import.rules.field.description',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('priority', IntegerType::class, [
                'label' => 'accounting.bank_import.rules.field.priority',
                'help' => 'accounting.bank_import.rules.field.priority.help',
                'attr' => ['min' => 0, 'max' => 999],
            ])
            ->add('isEnabled', CheckboxType::class, [
                'label' => 'accounting.bank_import.rules.field.enabled',
                'required' => false,
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
            ])
            ->add('bankAccount', EntityType::class, [
                'class' => AccountingAccount::class,
                'label' => 'accounting.bank_import.rules.field.bank_account',
                'help' => 'accounting.bank_import.rules.field.bank_account.help',
                'placeholder' => 'accounting.bank_import.rules.field.bank_account.global',
                'required' => false,
                'choice_label' => 'label',
                'query_builder' => fn (AccountingAccountRepository $repo) => $repo->createBankAccountsQueryBuilder($activePreset),
            ])
            ->add('conditions', CollectionType::class, [
                'label' => false,
                'entry_type' => BankImportRuleConditionType::class,
                'entry_options' => ['label' => false],
                'allow_add' => false,
                'allow_delete' => false,
            ])
            ->add('action', BankImportRuleActionType::class, [
                'label' => false,
                'account_choices' => $accountChoices,
                'tax_rate_choices' => $taxRateChoices,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BankImportRule::class]);
    }

    /**
     * Accounts the editor offers: those of the active chart preset, plus the
     * ones this rule points at today. Without the second half, saving a rule
     * that references an account outside the preset would silently blank the
     * reference.
     *
     * @return array<string, int> label => account id
     */
    private function accountChoices(BankImportRule $rule, ?string $preset): array
    {
        $choices = [];
        foreach ($this->accountRepo->findAllOrdered($preset) as $account) {
            $choices[$account->getLabel()] = (int) $account->getId();
        }

        foreach ($this->referencedIds($rule->getAction(), ['debitAccountId', 'creditAccountId']) as $id) {
            if (in_array($id, $choices, true)) {
                continue;
            }

            $account = $this->accountRepo->find($id);
            if (null !== $account) {
                $choices[$account->getLabel()] = $id;
            }
        }

        return $choices;
    }

    /**
     * Same for tax rates — a rule may well reference one whose validity has
     * since expired.
     *
     * @return array<string, int> label => tax rate id
     */
    private function taxRateChoices(BankImportRule $rule, ?string $preset): array
    {
        $choices = [];
        foreach ($this->taxRateRepo->findValidAt(new \DateTimeImmutable(), $preset) as $taxRate) {
            $choices[$this->taxRateLabel($taxRate)] = (int) $taxRate->getId();
        }

        foreach ($this->referencedIds($rule->getAction(), ['taxRateId']) as $id) {
            if (in_array($id, $choices, true)) {
                continue;
            }

            $taxRate = $this->taxRateRepo->find($id);
            if (null !== $taxRate) {
                $choices[$this->taxRateLabel($taxRate)] = $id;
            }
        }

        return $choices;
    }

    private function taxRateLabel(TaxRate $taxRate): string
    {
        return sprintf('%s (%s%%)', $taxRate->getName(), number_format($taxRate->getRateFloat(), 2, ',', '.'));
    }

    /**
     * Collects the ids an action references, both directly and per split row.
     *
     * @param array<string, mixed> $action
     * @param list<string>         $keys
     *
     * @return list<int>
     */
    private function referencedIds(array $action, array $keys): array
    {
        $rows = [$action];
        foreach (is_array($action['splits'] ?? null) ? $action['splits'] : [] as $split) {
            if (is_array($split)) {
                $rows[] = $split;
            }
        }

        $ids = [];
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $id = $row[$key] ?? null;
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
