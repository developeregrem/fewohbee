<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BankImportSettingsType extends AbstractType
{
    public const INVOICE_SAMPLE_FIELDS = ['invoiceNumberSample1', 'invoiceNumberSample2', 'invoiceNumberSample3'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
