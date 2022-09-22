<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CalendarSync;
use App\Entity\ReservationStatus;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CalendarSyncExportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('isPublic', CheckboxType::class, [
                'label' => 'calendar.sync.export.access.public.label',
                'help' => 'calendar.sync.export.access.public.hint',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
                ])
            ->add('exportGuestName', CheckboxType::class, [
                'label' => 'calendar.sync.export.option.guestname.label',
                'help' => 'calendar.sync.export.option.guestname.hint',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
                ])
            ->add('reservationStatus', EntityType::class, [
                // looks for choices from this entity
                'class' => ReservationStatus::class,
                'choice_label' => 'name',
                // used to render a select box, check boxes or radios
                'multiple' => true,
                'expanded' => true,
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'help' => 'calendar.sync.export.option.status.hint',
                ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CalendarSync::class,
        ]);
    }
}
