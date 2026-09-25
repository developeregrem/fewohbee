<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\GuestCheckIn\GuestCheckInCompanion;
use App\Entity\Enum\GuestCheckInFieldMode;
use App\Entity\GuestCheckInConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One fellow traveller on the public check-in form.
 *
 * With optional companions a block may stay empty and is dropped; once anything is entered, the
 * block counts and its required fields apply. With required companions every block counts.
 */
class GuestCheckInCompanionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var GuestCheckInConfig $config */
        $config = $options['config'];

        $builder
            ->add('firstname', TextType::class, [
                'label' => 'guest_checkin.public.field.firstname',
                'required' => false,
                'attr' => ['maxlength' => 45],
            ])
            ->add('lastname', TextType::class, [
                'label' => 'guest_checkin.public.field.lastname',
                'required' => false,
                'attr' => ['maxlength' => 45],
            ]);

        if ($config->getBirthdayMode()->isShown()) {
            $builder->add('birthday', DateType::class, [
                'label' => 'guest_checkin.public.field.birthday',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ]);
        }

        if ($config->getNationalityMode()->isShown()) {
            $builder->add('nationality', CountryType::class, [
                'label' => 'guest_checkin.public.field.nationality',
                'placeholder' => '',
                'required' => false,
            ]);
        }

        // Only guests who do not live with the main guest fill these in (template: collapsed).
        if ($config->getAddressMode()->isShown()) {
            $builder
                ->add('street', TextType::class, ['label' => 'guest_checkin.public.field.street', 'required' => false, 'attr' => ['autocomplete' => 'off', 'maxlength' => 150]])
                ->add('zip', TextType::class, ['label' => 'guest_checkin.public.field.zip', 'required' => false, 'attr' => ['autocomplete' => 'off', 'maxlength' => 10]])
                ->add('city', TextType::class, ['label' => 'guest_checkin.public.field.city', 'required' => false, 'attr' => ['autocomplete' => 'off', 'maxlength' => 45]])
                ->add('country', CountryType::class, ['label' => 'guest_checkin.public.field.country', 'placeholder' => '', 'required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCheckInCompanion::class,
        ]);
        $resolver->setRequired('config');
        $resolver->setAllowedTypes('config', GuestCheckInConfig::class);
        $resolver->setNormalizer('constraints', static function (Options $options, mixed $constraints): array {
            /** @var GuestCheckInConfig $config */
            $config = $options['config'];

            return [...(array) $constraints, new Assert\Callback(static function (?GuestCheckInCompanion $companion, ExecutionContextInterface $context) use ($config): void {
                if (null === $companion || ($companion->isEmpty() && GuestCheckInFieldMode::REQUIRED !== $config->getCompanionsMode())) {
                    return;
                }

                $required = ['firstname' => true, 'lastname' => true];
                $required['birthday'] = GuestCheckInFieldMode::REQUIRED === $config->getBirthdayMode();
                $required['nationality'] = GuestCheckInFieldMode::REQUIRED === $config->getNationalityMode();
                // A started own address has to be complete; none at all means "lives with the main guest".
                foreach (['street', 'zip', 'city', 'country'] as $field) {
                    $required[$field] = $companion->hasOwnAddress();
                }
                foreach ($required as $field => $isRequired) {
                    if ($isRequired && null === $companion->{$field}) {
                        $context->buildViolation('guest_checkin.companion_field_missing')->atPath($field)->addViolation();
                    }
                }
            })];
        });
    }
}
