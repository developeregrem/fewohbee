<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Calendar;
use App\Entity\CalendarEntry;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CalendarEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['include_calendar']) {
            $builder->add('calendar', EntityType::class, [
                'label' => 'calendar_entry.form.calendar',
                'class' => Calendar::class,
                'choice_label' => 'name',
                'query_builder' => fn ($repo) => $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC'),
            ]);
        }

        $builder
            ->add('date', DateType::class, [
                'label' => 'calendar_entry.form.date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            // Optional: left empty the entry stays all-day, which is what every
            // entry was before this field existed.
            ->add('time', TimeType::class, [
                'label' => 'calendar_entry.form.time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => 'calendar_entry.form.time_help',
            ]);

        if ($options['include_date_to']) {
            // Unmapped - CalendarEntry only ever stores a single day. When
            // set, the controller creates one entry per day in the range
            // instead of teaching the entity/every consumer about ranges
            // (see CalendarEntrySyncService, which does the same for
            // multi-day ICS events).
            $builder->add('dateTo', DateType::class, [
                'label' => 'calendar_entry.form.date_to',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'mapped' => false,
                'help' => 'calendar_entry.form.date_to_help',
            ]);
        }

        $builder->add('title', TextType::class, [
            'label' => 'calendar_entry.form.title',
            'attr' => ['maxlength' => 100],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CalendarEntry::class,
            'include_calendar' => false,
            'include_date_to' => false,
        ]);
    }
}
