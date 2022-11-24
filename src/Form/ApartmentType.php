<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Appartment;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;

class ApartmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('number', TextType::class, [
                'label' => 'appartment.number'
            ])
            ->add('description', TextType::class, [
                'label' => 'appartment.description'
            ])
            ->add('bedsMax', IntegerType::class, [
                'label' => 'appartment.bedsmax',
                'constraints' => [
                    new GreaterThan(['value' => 0])
                ]
            ])
            ->add('roomCategory', EntityType::class, [
                'class' => RoomCategory::class,
                'choice_label' => 'name',
                'label' => 'appartment.category'
            ])
            ->add('object', EntityType::class, [
                'class' => Subsidiary::class,
                'choice_label' => 'name',
                'label' => 'appartment.object'
            ])
            ->add('multipleOccupancy', CheckboxType::class, [
                'label' => 'apartment.multiple.occupancy.text',
                'help' => 'apartment.multiple.occupancy.hint',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Appartment::class,
        ]);
    }
}
