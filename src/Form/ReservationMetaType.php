<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReservationMetaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('remark', TextareaType::class, [
                'label' => 'customer.remark',
                'required' => false,
            ])
            ->add('reservationOrigin', EntityType::class, [
                'class' => ReservationOrigin::class,
                'choice_label' => 'name',
                'label' => 'reservation.origin',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
        ]);
    }
}
