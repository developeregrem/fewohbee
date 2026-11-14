<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AppSettings;
use App\Entity\Template;
use App\Service\InvoiceNumberPatternService;
use App\Service\Localization\GuestLocaleService;
use App\Service\PublicUrlService;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class AppSettingsType extends AbstractType
{
    public function __construct(
        private readonly InvoiceNumberPatternService $patternService,
        private readonly GuestLocaleService $guestLocales,
    ) {
    }

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
            ->add('invoiceNumberPattern', TextType::class, [
                'label' => 'app_settings.form.invoice_number_pattern',
                'help' => 'app_settings.form.invoice_number_pattern_help',
                'help_html' => true,
                'required' => false,
                'attr' => ['maxlength' => 100, 'placeholder' => '<year>-<number:4>'],
                'constraints' => [
                    new Assert\Callback(function (?string $pattern, ExecutionContextInterface $context): void {
                        foreach ($this->patternService->validate((string) $pattern) as $finding) {
                            // Warnings are informational; only errors block saving.
                            if ('error' === $finding['severity']) {
                                $context->buildViolation($finding['key'])
                                    ->setParameters($finding['params'])
                                    ->addViolation();
                            }
                        }
                    }),
                ],
            ])
            ->add('customerSalutations', CollectionType::class, [
                'label' => 'app_settings.form.customer_salutations',
                'help' => 'app_settings.form.customer_salutations_help',
                'mapped' => false,
                'entry_type' => CustomerSalutationType::class,
                'entry_options' => ['label' => false],
                'allow_add' => true,
                'allow_delete' => true,
                'prototype' => true,
            ])
            ->add('guestLocales', ChoiceType::class, [
                'label' => 'guest_locales.label',
                'help' => 'guest_locales.help',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices' => array_combine(
                    array_map(fn (string $locale): string => $this->guestLocales->languageName($locale, false), $this->guestLocales->enabledLocales()),
                    $this->guestLocales->enabledLocales(),
                ),
                'choice_translation_domain' => false,
                // The installation language is always offered; the controller adds it back.
                'choice_attr' => fn (string $locale): array => $locale === $this->guestLocales->baseLocale()
                    ? ['disabled' => 'disabled']
                    : [],
            ])
            ->add('confirmationTemplate', EntityType::class, [
                'class' => Template::class,
                'required' => false,
                'placeholder' => 'app_settings.form.confirmation_template_none',
                'label' => 'app_settings.form.confirmation_template',
                'help' => 'app_settings.form.confirmation_template_help',
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => self::baseTemplates($repository, ['TEMPLATE_RESERVATION_EMAIL']),
            ])
            ->add('confirmationAttachments', EntityType::class, [
                'class' => Template::class,
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'by_reference' => false,
                'label' => 'app_settings.form.confirmation_attachments',
                'help' => 'app_settings.form.confirmation_attachments_help',
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => self::baseTemplates($repository, ['TEMPLATE_RESERVATION_PDF', 'TEMPLATE_FILE_PDF']),
            ])
            ->add('confirmationSendImmediately', CheckboxType::class, [
                'required' => false,
                'label' => 'app_settings.form.confirmation_send_immediately',
                'help' => 'app_settings.form.confirmation_send_immediately_help',
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

        // A hosting provider that presets PUBLIC_BASE_URI owns the address; the page shows it read-only.
        if (!$options['public_base_url_locked']) {
            $builder->add('publicBaseUrl', TextType::class, [
                'label' => 'app_settings.form.public_base_url',
                'help' => 'app_settings.form.public_base_url_help',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => 'https://fewohbee.example.com', 'inputmode' => 'url'],
                'constraints' => [
                    new Assert\Length(max: 255),
                    new Assert\Callback(static function (?string $url, ExecutionContextInterface $context): void {
                        if (null !== $url && '' !== trim($url) && null === PublicUrlService::normalize($url)) {
                            $context->buildViolation('public_base_url.invalid')->addViolation();
                        }
                    }),
                ],
            ]);
        }
    }

    /**
     * Visible templates of the given types, without language variants: those follow their base
     * template in the booker's language.
     *
     * @param EntityRepository<Template> $repository
     * @param list<string>               $typeNames
     */
    private static function baseTemplates(EntityRepository $repository, array $typeNames): QueryBuilder
    {
        return $repository->createQueryBuilder('t')
            ->join('t.templateType', 'tt')
            ->where('tt.name IN (:types)')
            ->andWhere('t.hidden = false')
            ->andWhere('t.baseTemplate IS NULL')
            ->setParameter('types', $typeNames)
            ->orderBy('t.name', 'ASC');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AppSettings::class,
            'public_base_url_locked' => false,
        ]);
        $resolver->setAllowedTypes('public_base_url_locked', 'bool');
    }
}
