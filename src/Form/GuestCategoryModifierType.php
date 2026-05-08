<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\ModifierType;
use App\Entity\GuestCategory;
use App\Entity\GuestCategoryModifier;
use App\Repository\GuestCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GuestCategoryModifierType extends AbstractType
{
    public function __construct(
        private readonly GuestCategoryRepository $guestCategoryRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', EntityType::class, [
                'class' => GuestCategory::class,
                'choice_label' => 'name',
                'choices' => $this->guestCategoryRepository->findActiveNonAdultOrdered(),
                'label' => 'guest_category_modifier.field.category',
                'help' => 'guest_category_modifier.field.category.help',
            ])
            ->add('type', EnumType::class, [
                'class' => ModifierType::class,
                'choice_label' => fn (ModifierType $t) => 'guest_category_modifier.type.'.$t->value,
                'label' => 'guest_category_modifier.field.type',
                'help' => 'guest_category_modifier.field.type.help',
            ])
            ->add('value', NumberType::class, [
                'label' => 'guest_category_modifier.field.value',
                'help' => 'guest_category_modifier.field.value.help',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
            ->add('validFrom', DateType::class, [
                'label' => 'guest_category_modifier.field.valid_from',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('validTo', DateType::class, [
                'label' => 'guest_category_modifier.field.valid_to',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'guest_category_modifier.field.sort_order',
                'empty_data' => '0',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'guest_category_modifier.field.active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GuestCategoryModifier::class,
        ]);
    }
}
