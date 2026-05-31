<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AppSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
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
            ->add('mailFromEmail', EmailType::class, [
                'label' => 'app_settings.form.mail_from_email',
                'required' => true,
                'attr' => ['placeholder' => 'app_settings.form.mail_from_email_placeholder'],
            ])
            ->add('mailFromName', TextType::class, [
                'label' => 'app_settings.form.mail_from_name',
                'required' => false,
                'attr' => ['placeholder' => 'app_settings.form.mail_from_name_placeholder'],
            ])
            ->add('mailReturnPath', EmailType::class, [
                'label' => 'app_settings.form.mail_return_path',
                'required' => false,
                'help' => 'app_settings.form.mail_return_path_help',
            ])
            ->add('mailCopy', CheckboxType::class, [
                'label' => 'app_settings.form.mail_copy',
                'required' => false,
                'help' => 'app_settings.form.mail_copy_help',
            ])
            ->add('smtpHost', TextType::class, [
                'label' => 'app_settings.form.smtp_host',
                'required' => false,
                'attr' => ['placeholder' => 'smtp.example.com'],
            ])
            ->add('smtpPort', IntegerType::class, [
                'label' => 'app_settings.form.smtp_port',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 65535, 'placeholder' => '587'],
            ])
            ->add('smtpEncryption', ChoiceType::class, [
                'label' => 'app_settings.form.smtp_encryption',
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'app_settings.form.smtp_encryption_starttls' => 'starttls',
                    'app_settings.form.smtp_encryption_ssl' => 'ssl',
                    'app_settings.form.smtp_encryption_none' => 'none',
                ],
            ])
            ->add('smtpUsername', TextType::class, [
                'label' => 'app_settings.form.smtp_username',
                'required' => false,
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label' => 'app_settings.form.smtp_password',
                'required' => false,
                'mapped' => false,
                'always_empty' => true,
                'help' => 'app_settings.form.smtp_password_help',
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
