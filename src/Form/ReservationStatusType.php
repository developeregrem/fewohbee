<?php

namespace App\Form;

use App\Entity\ReservationStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ColorType;

class ReservationStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name')
            ->add('color', ColorType::class, [
                'label' => 'status.color',
                'html5' => true
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => ReservationStatus::class,
        ]);
    }
}
