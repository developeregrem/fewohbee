<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\GuestCheckIn\GuestCheckInSubmission;
use App\Entity\GuestCheckInConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Public online check-in form. Works without JavaScript: the number of fellow-traveller blocks
 * is fixed by the reservation, so nothing is added or removed in the browser.
 */
class GuestCheckInType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var GuestCheckInConfig $config */
        $config = $options['config'];
        $builder
            // A native time field instead of a list of slots: compact, and phones open their own
            // time picker. The template puts the house's check-in window right below as a hint.
            ->add('arrivalTime', TimeType::class, [
                'label' => 'guest_checkin.public.field.arrival_time',
                'widget' => 'single_text',
                'input' => 'string',
                'input_format' => 'H:i',
                'with_seconds' => false,
                'attr' => ['step' => 900, 'class' => 'fhb-gci-time'],
            ])
            ->add('mainGuest', GuestCheckInGuestType::class, [
                'label' => false,
                'config' => $config,
                'salutations' => $options['salutations'],
                'stored_id_hint' => $options['stored_id_hint'],
            ]);

        if ($config->getCompanionsMode()->isShown() && $options['companion_count'] > 0) {
            $builder->add('companions', CollectionType::class, [
                'label' => false,
                'entry_type' => GuestCheckInCompanionType::class,
                'entry_options' => ['config' => $config, 'label' => false],
                'allow_add' => false,
                'allow_delete' => false,
            ]);
        }

        $builder->add('message', TextareaType::class, [
            'label' => 'guest_checkin.public.field.message',
            'required' => false,
            'attr' => ['rows' => 3, 'maxlength' => 1000],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCheckInSubmission::class,
            'companion_count' => 0,
            'stored_id_hint' => null,
            // See GuestCheckInVerificationType: the link token is the credential, and the proof
            // of the booking-details check is a SameSite=Strict cookie browsers do not send on
            // cross-site posts.
            'csrf_protection' => false,
        ]);
        $resolver->setRequired(['config', 'salutations']);
        $resolver->setAllowedTypes('config', GuestCheckInConfig::class);
        $resolver->setAllowedTypes('salutations', 'string[]');
        $resolver->setAllowedTypes('companion_count', 'int');
        $resolver->setAllowedTypes('stored_id_hint', ['null', 'string']);
    }
}
