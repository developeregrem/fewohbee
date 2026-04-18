<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AppSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AppSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currency', TextType::class, [
                'label' => 'app_settings.form.currency',
                'attr' => ['maxlength' => 3, 'placeholder' => 'EUR'],
            ])
            ->add('currencySymbol', TextType::class, [
                'label' => 'app_settings.form.currency_symbol',
                'attr' => ['maxlength' => 5, 'placeholder' => '€'],
            ])
            ->add('invoiceFilenamePattern', TextType::class, [
                'label' => 'app_settings.form.invoice_filename_pattern',
                'help' => 'app_settings.form.invoice_filename_pattern_help',
                'help_html' => true,
            ])
            ->add('customerSalutations', TextType::class, [
                'label' => 'app_settings.form.customer_salutations',
                'help' => 'app_settings.form.customer_salutations_help',
                'mapped' => false,
            ])
            ->add('notificationEmail', EmailType::class, [
                'label' => 'app_settings.form.notification_email',
                'required' => false,
                'help' => 'app_settings.form.notification_email_help',
                'help_html' => true,
                'attr' => ['placeholder' => 'app_settings.form.notification_email_placeholder'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AppSettings::class,
        ]);
    }
}
