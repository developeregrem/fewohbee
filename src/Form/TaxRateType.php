<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Entity\TaxRate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaxRateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'accounting.taxrates.name_header',
                'attr' => [
                    'maxlength' => 80,
                    'placeholder' => 'accounting.taxrates.name.placeholder',
                ],
            ])
            ->add('rate', NumberType::class, [
                'label' => 'accounting.taxrates.rate',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ])
            ->add('datevBuKey', TextType::class, [
                'label' => 'accounting.taxrates.bukey',
                'required' => false,
                'attr' => [
                    'maxlength' => 4,
                    'placeholder' => 'accounting.taxrates.bukey.placeholder',
                ],
                'help' => 'accounting.taxrates.bukey.help',
            ])
            ->add('validFrom', DateType::class, [
                'label' => 'accounting.taxrates.valid_from',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('validTo', DateType::class, [
                'label' => 'accounting.taxrates.valid_to',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('revenueAccount', EntityType::class, [
                'class' => AccountingAccount::class,
                'label' => 'accounting.taxrates.revenue_account',
                'required' => false,
                'placeholder' => '–',
                'choice_label' => fn (AccountingAccount $a) => $a->getAccountNumber().' – '.$a->getName(),
                'help' => 'accounting.taxrates.revenue_account.help',
            ])
            ->add('isDefault', CheckboxType::class, [
                'label' => 'accounting.taxrates.is_default',
                'required' => false,
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
            'data_class' => TaxRate::class,
        ]);
    }
}
