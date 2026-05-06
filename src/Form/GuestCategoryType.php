<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\GuestStatisticalGroup;
use App\Entity\GuestCategory;
use App\Entity\Subsidiary;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GuestCategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'guest_category.field.name',
                'empty_data' => '',
            ])
            ->add('acronym', TextType::class, [
                'label' => 'guest_category.field.acronym',
                'empty_data' => '',
                'help' => 'guest_category.field.acronym.help',
            ])
            ->add('statisticalGroup', EnumType::class, [
                'class' => GuestStatisticalGroup::class,
                'label' => 'guest_category.field.statistical_group',
                'choice_label' => fn (GuestStatisticalGroup $g) => 'guest_category.statistical_group.'.$g->value,
                'help' => 'guest_category.field.statistical_group.help',
            ])
            ->add('isCountedInOccupancy', CheckboxType::class, [
                'label' => 'guest_category.field.is_counted_in_occupancy',
                'help' => 'guest_category.field.is_counted_in_occupancy.help',
                'required' => false,
            ])
            ->add('minAge', IntegerType::class, [
                'label' => 'guest_category.field.min_age',
                'required' => false,
            ])
            ->add('maxAge', IntegerType::class, [
                'label' => 'guest_category.field.max_age',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'guest_category.field.sort_order',
                'empty_data' => '0',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'guest_category.field.active',
                'required' => false,
            ])
            ->add('subsidiaries', EntityType::class, [
                'class' => Subsidiary::class,
                'choice_label' => 'name',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'guest_category.field.subsidiaries',
                'help' => 'guest_category.field.subsidiaries.help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCategory::class,
        ]);
    }
}
