<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Entity\BankImportRule;
use App\Repository\AccountingAccountRepository;
use App\Service\BookingJournal\AccountingSettingsService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Edits the metadata of an existing {@see BankImportRule}: name, priority,
 * scope, enabled flag. Conditions and the action body are intentionally not
 * exposed here — they're authored from a concrete statement line via the
 * preview's "Als Regel speichern"-modal where the right context is at hand.
 */
class BankImportRuleType extends AbstractType
{
    public function __construct(
        private readonly AccountingSettingsService $settingsService,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $activePreset = $this->settingsService->getActivePreset();

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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BankImportRule::class]);
    }
}
