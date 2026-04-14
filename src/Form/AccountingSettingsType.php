<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountingSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('advisorNumber', TextType::class, [
                'label' => 'accounting.settings.advisor_number',
                'required' => false,
                'attr' => [
                    'maxlength' => 10,
                    'placeholder' => 'accounting.settings.advisor_number.placeholder',
                ],
            ])
            ->add('clientNumber', TextType::class, [
                'label' => 'accounting.settings.client_number',
                'required' => false,
                'attr' => [
                    'maxlength' => 10,
                    'placeholder' => 'accounting.settings.client_number.placeholder',
                ],
            ])
            ->add('fiscalYearStart', ChoiceType::class, [
                'label' => 'accounting.settings.fiscal_year_start',
                'choices' => array_combine(range(1, 12), range(1, 12)),
                'choice_translation_domain' => false,
            ])
            ->add('accountNumberLength', ChoiceType::class, [
                'label' => 'accounting.settings.account_number_length',
                'choices' => [4 => 4, 5 => 5],
                'choice_translation_domain' => false,
            ])
            ->add('dictationCode', TextType::class, [
                'label' => 'accounting.settings.dictation_code',
                'required' => false,
                'attr' => ['maxlength' => 5],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountingSettings::class,
        ]);
    }
}
