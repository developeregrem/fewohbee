<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\GuestCheckInFieldMode;
use App\Entity\GuestCheckInConfig;
use App\Service\PublicUrlService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class GuestCheckInConfigType extends AbstractType
{
    /** Field groups that can be hidden, offered or required, in the order they appear on the form. */
    public const FIELD_GROUPS = ['addressMode', 'birthdayMode', 'nationalityMode', 'idDocumentMode', 'contactMode', 'companionsMode'];

    public function __construct(
        private readonly PublicUrlService $publicUrlService,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('enabled', CheckboxType::class, [
            'required' => false,
            'label' => 'guest_checkin.settings.enabled',
            'label_attr' => ['class' => 'checkbox-switch'],
            'constraints' => [
                // Links are built from the public address; without it nothing could be sent.
                new Assert\Callback(function (?bool $enabled, ExecutionContextInterface $context): void {
                    if (true === $enabled && null === $this->publicUrlService->getBaseUrl()) {
                        $context->buildViolation('guest_checkin.public_address_missing')->addViolation();
                    }
                }),
            ],
        ]);

        foreach (self::FIELD_GROUPS as $field) {
            $builder->add($field, EnumType::class, [
                'class' => GuestCheckInFieldMode::class,
                'choice_label' => static fn (GuestCheckInFieldMode $mode): string => $mode->labelKey(),
                'label' => 'guest_checkin.settings.field.'.$field,
                'help' => 'guest_checkin.settings.field.'.$field.'_help',
            ]);
        }

        $builder
            ->add('introText', TextareaType::class, [
                'required' => false,
                'label' => 'guest_checkin.settings.intro_text',
                'help' => 'guest_checkin.settings.intro_text_help',
                'attr' => ['rows' => 4, 'maxlength' => 2000],
            ])
            ->add('privacyUrl', UrlType::class, [
                'required' => false,
                'default_protocol' => 'https',
                'label' => 'guest_checkin.settings.privacy_url',
                'attr' => ['maxlength' => 255, 'placeholder' => 'https://'],
            ])
            ->add('privacyText', TextareaType::class, [
                'required' => false,
                'label' => 'guest_checkin.settings.privacy_text',
                'help' => 'guest_checkin.settings.privacy_text_help',
                'attr' => ['rows' => 3, 'maxlength' => 2000],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCheckInConfig::class,
        ]);
    }
}
