<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\Pricing\PriceRuleData;
use App\Entity\Enum\PriceRuleCondition;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The fields of the price rule offcanvas; the template lays them out as sentences. */
final class PriceRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'price_rules.name',
                // An emptied field must reach the NotBlank check instead of failing as null.
                'empty_data' => '',
                'attr' => ['maxlength' => 100],
            ])
            ->add('condition', EnumType::class, [
                'class' => PriceRuleCondition::class,
                'label' => 'price_rules.condition',
                'choice_label' => static fn (PriceRuleCondition $condition): string => 'price_rules.condition_'.$condition->value,
                'placeholder' => false,
            ])
            ->add('days', IntegerType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['min' => 0, 'max' => 730],
            ])
            ->add('occupancy', IntegerType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100],
            ])
            ->add('occupancyAcrossSubsidiaries', CheckboxType::class, [
                'label' => 'price_rules.occupancy_across',
                'required' => false,
            ])
            ->add('raise', ChoiceType::class, [
                'label' => false,
                'expanded' => true,
                'choices' => ['price_rules.raise' => true, 'price_rules.lower' => false],
            ])
            ->add('amount', NumberType::class, [
                'label' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0.01, 'step' => 1],
            ])
            ->add('weekdays', ChoiceType::class, [
                'label' => 'price_rules.weekdays',
                'expanded' => true,
                'multiple' => true,
                'choices' => array_combine(
                    array_map(static fn (int $day): string => 'booking_rules.night_'.$day, range(1, 7)),
                    range(1, 7),
                ),
            ])
            ->add('firstNight', DateType::class, [
                'label' => 'price_rules.first_night',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('lastNight', DateType::class, [
                'label' => 'price_rules.last_night',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('allSubsidiaries', CheckboxType::class, [
                'label' => 'price_rules.all_subsidiaries',
                'required' => false,
            ])
            ->add('subsidiaries', EntityType::class, [
                'class' => Subsidiary::class,
                'choice_label' => 'name',
                'label' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('allCategories', CheckboxType::class, [
                'label' => 'price_rules.all_categories',
                'required' => false,
            ])
            ->add('categories', EntityType::class, [
                'class' => RoomCategory::class,
                'choice_label' => 'name',
                'label' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Its own token id keeps this form on session-bound CSRF rather than the app-wide
        // stateless "submit" id: the offcanvas posts through a plain fetch.
        $resolver->setDefaults([
            'data_class' => PriceRuleData::class,
            'csrf_token_id' => 'price_rule',
        ]);
    }
}
