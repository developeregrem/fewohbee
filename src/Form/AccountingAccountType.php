<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountingAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('accountNumber', TextType::class, [
                'label' => 'accounting.accounts.number',
                'attr' => ['maxlength' => 10],
            ])
            ->add('name', TextType::class, [
                'label' => 'accounting.accounts.name',
                'attr' => ['maxlength' => 150],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'accounting.accounts.type',
                'choices' => array_combine(
                    array_map(fn (string $t) => 'accounting.accounts.type.'.$t, AccountingAccount::VALID_TYPES),
                    AccountingAccount::VALID_TYPES,
                ),
            ])
            ->add('isCashAccount', CheckboxType::class, [
                'label' => 'accounting.accounts.is_cash',
                'required' => false,
            ])
            ->add('isBankAccount', CheckboxType::class, [
                'label' => 'accounting.accounts.is_bank',
                'required' => false,
            ])
            ->add('iban', TextType::class, [
                'label' => 'accounting.accounts.iban',
                'required' => false,
                'help' => 'accounting.accounts.iban.help',
                'attr' => ['maxlength' => 34, 'placeholder' => 'DE00…'],
            ])
            ->add('isOpeningBalanceAccount', CheckboxType::class, [
                'label' => 'accounting.accounts.is_opening_balance',
                'required' => false,
            ])
            ->add('isAutoAccount', CheckboxType::class, [
                'label' => 'accounting.accounts.is_auto',
                'required' => false,
                'help' => 'accounting.accounts.is_auto.help',
            ])
            ->add('datevSachverhaltLuL', IntegerType::class, [
                'label' => 'accounting.accounts.sachverhalt_lul',
                'required' => false,
                'help' => 'accounting.accounts.sachverhalt_lul.help',
                'attr' => ['min' => 0, 'max' => 99],
            ])
            ->add('datevFunktionsergaenzungLuL', IntegerType::class, [
                'label' => 'accounting.accounts.funktion_lul',
                'required' => false,
                'help' => 'accounting.accounts.funktion_lul.help',
                'attr' => ['min' => 0, 'max' => 999],
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'accounting.accounts.sort_order',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountingAccount::class,
        ]);
    }
}
