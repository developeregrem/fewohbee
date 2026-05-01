<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccountingSettingsType extends AbstractType
{
    public const INVOICE_SAMPLE_FIELDS = ['invoiceNumberSample1', 'invoiceNumberSample2', 'invoiceNumberSample3'];

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
            ->add('mainPositionLabel', TextType::class, [
                'label' => 'accounting.settings.main_position_label',
                'required' => false,
                'attr' => [
                    'maxlength' => 60,
                    'placeholder' => 'accounting.settings.main_position_label.placeholder',
                ],
            ])
            ->add('miscPositionLabel', TextType::class, [
                'label' => 'accounting.settings.misc_position_label',
                'required' => false,
                'attr' => [
                    'maxlength' => 60,
                    'placeholder' => 'accounting.settings.misc_position_label.placeholder',
                ],
            ])
        ;

        // Three synthetic text fields capture user-supplied invoice number
        // examples; on submit they are folded into the JSON property
        // {@see AccountingSettings::$invoiceNumberSamples}.
        foreach (self::INVOICE_SAMPLE_FIELDS as $i => $name) {
            $builder->add($name, TextType::class, [
                'label' => 'accounting.settings.invoice_number_sample.'.($i + 1),
                'required' => false,
                'mapped' => false,
                'attr' => ['maxlength' => 50, 'placeholder' => match ($i) {
                    0 => 'RE-12345',
                    1 => '2026-0001',
                    default => '',
                }],
            ]);
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            /** @var AccountingSettings|null $settings */
            $settings = $event->getData();
            if (!$settings instanceof AccountingSettings) {
                return;
            }

            $samples = $settings->getInvoiceNumberSamples();
            $form = $event->getForm();
            foreach (self::INVOICE_SAMPLE_FIELDS as $i => $name) {
                if ($form->has($name)) {
                    $form->get($name)->setData($samples[$i] ?? '');
                }
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            /** @var AccountingSettings|null $settings */
            $settings = $event->getData();
            if (!$settings instanceof AccountingSettings) {
                return;
            }

            $form = $event->getForm();
            $samples = [];
            foreach (self::INVOICE_SAMPLE_FIELDS as $name) {
                $samples[] = (string) ($form->get($name)->getData() ?? '');
            }
            $settings->setInvoiceNumberSamples($samples);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountingSettings::class,
        ]);
    }
}
