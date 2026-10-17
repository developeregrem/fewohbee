<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\GuestCheckIn\GuestCheckInVerification;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Booking-details check in front of the public check-in page (GuestCheckInVerifier). */
class GuestCheckInVerificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('arrival', DateType::class, [
                'label' => 'guest_checkin.public.verify.arrival',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('departure', DateType::class, [
                'label' => 'guest_checkin.public.verify.departure',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ]);

        // Imported portal bookings often have no name yet; then the dates are all there is.
        if ($options['ask_last_name']) {
            $builder->add('lastname', TextType::class, [
                'label' => 'guest_checkin.public.verify.lastname',
                'help' => 'guest_checkin.public.verify.lastname_help',
                'constraints' => [new Assert\NotBlank()],
                'attr' => ['autocomplete' => 'family-name', 'maxlength' => 100],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCheckInVerification::class,
            'ask_last_name' => true,
            // The link token in the URL is the credential and no cookie authenticates this
            // request, so a forged cross-site post could not do anything the attacker could
            // not already do with the link itself.
            'csrf_protection' => false,
        ]);
        $resolver->setAllowedTypes('ask_last_name', 'bool');
    }
}
