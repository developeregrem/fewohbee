<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CalendarSyncImport;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Url;

/** Build a form for configuring iCal imports. */
class CalendarSyncImportType extends AbstractType
{
    /** Configure the import form fields. */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'calendar.sync.import.name.label',
                'help' => 'calendar.sync.import.name.hint',
            ])
            ->add('url', UrlType::class, [
                'label' => 'calendar.sync.import.url.label',
                'help' => 'calendar.sync.import.url.hint',
                'constraints' => [
                    new Url(),
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'calendar.sync.import.active.label',
                'help' => 'calendar.sync.import.active.hint',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
            ->add('conflictStrategy', ChoiceType::class, [
                'label' => 'calendar.sync.import.conflict.label',
                'help' => 'calendar.sync.import.conflict.hint',
                'choices' => [
                    'calendar.sync.import.conflict.option.skip' => CalendarSyncImport::CONFLICT_SKIP,
                    'calendar.sync.import.conflict.option.overwrite' => CalendarSyncImport::CONFLICT_OVERWRITE,
                    'calendar.sync.import.conflict.option.mark' => CalendarSyncImport::CONFLICT_MARK,
                ],
            ])
            ->add('reservationOrigin', EntityType::class, [
                'class' => ReservationOrigin::class,
                'choice_label' => 'name',
                'label' => 'calendar.sync.import.origin.label',
                'help' => 'calendar.sync.import.origin.hint',
                'required' => true,
            ])
            ->add('reservationStatus', EntityType::class, [
                'class' => ReservationStatus::class,
                'choice_label' => 'name',
                'label' => 'calendar.sync.import.status.label',
                'help' => 'calendar.sync.import.status.hint',
                'required' => true,
            ])
        ;
    }

    /** Attach the form to the CalendarSyncImport entity. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CalendarSyncImport::class,
        ]);
    }
}
