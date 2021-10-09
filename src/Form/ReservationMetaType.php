<?php

namespace App\Form;

use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

class ReservationMetaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('remark', TextareaType::class, ['label' => 'customer.remark'])
            ->add('reservationOrigin', EntityType::class, [
                'class' => ReservationOrigin::class,
                'choice_label' => 'name',
                'label' => 'reservation.origin'
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
        ]);
    }
}
