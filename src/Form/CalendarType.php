<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Calendar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CalendarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'calendar.form.name',
                'attr' => ['maxlength' => 100],
            ])
            ->add('color', ColorType::class, [
                'label' => 'calendar.form.color',
                'html5' => true,
            ])
            ->add('requiresConfirmation', CheckboxType::class, [
                'label' => 'calendar.form.requires_confirmation',
                'required' => false,
                'help' => 'calendar.form.requires_confirmation_help',
            ])
            ->add('icsUrl', UrlType::class, [
                'label' => 'calendar.form.ics_url',
                'required' => false,
                'help' => 'calendar.form.ics_url_help',
                'attr' => ['placeholder' => 'https://...'],
            ])
            ->add('icsFile', FileType::class, [
                'label' => 'calendar.form.ics_file',
                'required' => false,
                'mapped' => false,
                'help' => 'calendar.form.ics_file_help',
                'constraints' => [
                    new Assert\File(
                        maxSize: '2m',
                        extensions: ['ics'],
                        extensionsMessage: 'calendar.form.ics_file_invalid',
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Calendar::class,
        ]);
    }
}
