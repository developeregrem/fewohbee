<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Entity\Enum\PercentageBase;
use App\Entity\Enum\TaxCalculationMode;
use App\Entity\Subsidiary;
use App\Entity\TaxRate;
use App\Entity\TouristTax;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TouristTaxType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $referenceDate = $options['reference_date'];
        $activePreset = $options['active_preset'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'tourist_tax.field.name',
                'empty_data' => '',
            ])
            ->add('calculationMode', EnumType::class, [
                'class' => TaxCalculationMode::class,
                'choice_label' => fn (TaxCalculationMode $m) => 'tourist_tax.calculation_mode.'.$m->value,
                'label' => 'tourist_tax.field.calculation_mode',
                'help' => 'tourist_tax.field.calculation_mode.help',
            ])
            ->add('percentageRate', NumberType::class, [
                'label' => 'tourist_tax.field.percentage_rate',
                'help' => 'tourist_tax.field.percentage_rate.help',
                'scale' => 2,
                'required' => false,
            ])
            ->add('percentageBase', EnumType::class, [
                'class' => PercentageBase::class,
                'choice_label' => fn (PercentageBase $b) => 'tourist_tax.percentage_base.'.$b->value,
                'label' => 'tourist_tax.field.percentage_base',
                'help' => 'tourist_tax.field.percentage_base.help',
                'required' => false,
                'placeholder' => '-',
            ])
            ->add('taxRate', EntityType::class, [
                'class' => TaxRate::class,
                'required' => false,
                'placeholder' => '-',
                'choice_label' => 'label',
                'query_builder' => fn (TaxRateRepository $repo) => $repo->createValidAtQueryBuilder($referenceDate, $activePreset),
                'label' => 'tourist_tax.field.tax_rate',
                'help' => 'tourist_tax.field.tax_rate.help',
            ])
            ->add('revenueAccount', EntityType::class, [
                'class' => AccountingAccount::class,
                'required' => false,
                'placeholder' => '-',
                'choice_label' => 'label',
                'query_builder' => fn (AccountingAccountRepository $repo) => $repo->createOrderedQueryBuilder($activePreset),
                'label' => 'tourist_tax.field.revenue_account',
            ])
            ->add('includesVat', CheckboxType::class, [
                'label' => 'tourist_tax.field.includes_vat',
                'help' => 'tourist_tax.field.includes_vat.help',
                'required' => false,
            ])
            ->add('appliesOnlyToAdult', CheckboxType::class, [
                'label' => 'tourist_tax.field.applies_only_to_adult',
                'required' => false,
            ])
            ->add('validFrom', DateType::class, [
                'label' => 'tourist_tax.field.valid_from',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('validTo', DateType::class, [
                'label' => 'tourist_tax.field.valid_to',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'tourist_tax.field.sort_order',
                'empty_data' => '0',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'tourist_tax.field.active',
                'required' => false,
            ])
            ->add('subsidiaries', EntityType::class, [
                'class' => Subsidiary::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'tourist_tax.field.subsidiaries',
                'help' => 'tourist_tax.field.subsidiaries.help',
            ])
            ->add('rates', CollectionType::class, [
                'entry_type' => TouristTaxRateType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'label' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TouristTax::class,
            'reference_date' => new \DateTime(),
            'active_preset' => null,
        ]);
    }
}
