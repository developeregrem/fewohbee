<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\GuestCheckIn\GuestCheckInGuest;
use App\Entity\Enum\GuestCheckInFieldMode;
use App\Entity\Enum\IDCardType;
use App\Entity\GuestCheckInConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Main guest on the public check-in form; groups are shown and required per GuestCheckInConfig. */
class GuestCheckInGuestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var GuestCheckInConfig $config */
        $config = $options['config'];

        $builder
            ->add('salutation', ChoiceType::class, [
                'label' => 'guest_checkin.public.field.salutation',
                'choices' => array_combine($options['salutations'], $options['salutations']),
                'placeholder' => '',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['autocomplete' => 'honorific-prefix'],
            ])
            ->add('firstname', TextType::class, $this->text('firstname', GuestCheckInFieldMode::REQUIRED, 'given-name', 45))
            ->add('lastname', TextType::class, $this->text('lastname', GuestCheckInFieldMode::REQUIRED, 'family-name', 45));

        if ($config->getBirthdayMode()->isShown()) {
            $builder->add('birthday', DateType::class, [
                'label' => 'guest_checkin.public.field.birthday',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => GuestCheckInFieldMode::REQUIRED === $config->getBirthdayMode(),
                'constraints' => $this->requiredConstraints($config->getBirthdayMode()),
                'attr' => ['autocomplete' => 'bday'],
            ]);
        }

        if ($config->getNationalityMode()->isShown()) {
            $builder->add('nationality', CountryType::class, $this->country('nationality', $config->getNationalityMode()));
        }

        if ($config->getIdDocumentMode()->isShown()) {
            $mode = $config->getIdDocumentMode();
            $storedIdHint = $options['stored_id_hint'];
            $builder
                ->add('idType', EnumType::class, [
                    'label' => 'guest_checkin.public.field.id_type',
                    'class' => IDCardType::class,
                    'choice_label' => static fn (IDCardType $type): string => $type->value,
                    'placeholder' => '',
                    'required' => GuestCheckInFieldMode::REQUIRED === $mode,
                    'constraints' => $this->requiredConstraints($mode),
                ])
                ->add('idNumber', TextType::class, [
                    'label' => 'guest_checkin.public.field.id_number',
                    // A number on file is never sent to the browser, only its last characters;
                    // leaving the field empty keeps it.
                    'help' => null !== $storedIdHint ? 'guest_checkin.public.field.id_number_keep' : null,
                    'help_translation_parameters' => ['%hint%' => (string) $storedIdHint],
                    'required' => GuestCheckInFieldMode::REQUIRED === $mode && null === $storedIdHint,
                    'constraints' => null !== $storedIdHint ? [] : $this->requiredConstraints($mode),
                    'attr' => ['autocomplete' => 'off', 'maxlength' => 30],
                ]);
        }

        if ($config->getAddressMode()->isShown()) {
            $mode = $config->getAddressMode();
            $builder
                ->add('street', TextType::class, $this->text('street', $mode, 'street-address', 150))
                ->add('zip', TextType::class, $this->text('zip', $mode, 'postal-code', 10))
                ->add('city', TextType::class, $this->text('city', $mode, 'address-level2', 45))
                ->add('country', CountryType::class, $this->country('country', $mode) + ['attr' => ['autocomplete' => 'country']]);
        }

        if ($config->getContactMode()->isShown()) {
            $mode = $config->getContactMode();
            $builder
                ->add('email', EmailType::class, $this->text('email', $mode, 'email', 100))
                ->add('phone', TelType::class, $this->text('phone', $mode, 'tel', 30));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCheckInGuest::class,
            'stored_id_hint' => null,
        ]);
        $resolver->setRequired(['config', 'salutations']);
        $resolver->setAllowedTypes('config', GuestCheckInConfig::class);
        $resolver->setAllowedTypes('salutations', 'string[]');
        $resolver->setAllowedTypes('stored_id_hint', ['null', 'string']);
    }

    /** @return array<string, mixed> */
    private function text(string $field, GuestCheckInFieldMode $mode, string $autocomplete, int $maxLength): array
    {
        return [
            'label' => 'guest_checkin.public.field.'.$field,
            'required' => GuestCheckInFieldMode::REQUIRED === $mode,
            'constraints' => $this->requiredConstraints($mode),
            'attr' => ['autocomplete' => $autocomplete, 'maxlength' => $maxLength],
        ];
    }

    /** @return array<string, mixed> */
    private function country(string $field, GuestCheckInFieldMode $mode): array
    {
        return [
            'label' => 'guest_checkin.public.field.'.$field,
            'placeholder' => '',
            'required' => GuestCheckInFieldMode::REQUIRED === $mode,
            'constraints' => $this->requiredConstraints($mode),
        ];
    }

    /** @return list<Assert\NotBlank> */
    private function requiredConstraints(GuestCheckInFieldMode $mode): array
    {
        return GuestCheckInFieldMode::REQUIRED === $mode ? [new Assert\NotBlank()] : [];
    }
}
