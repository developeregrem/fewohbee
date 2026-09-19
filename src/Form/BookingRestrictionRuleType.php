<?php

declare(strict_types=1);

namespace App\Form;

use App\Dto\BookingRestriction\RuleData;
use App\Entity\Enum\BookingRestrictionType;
use App\Entity\RoomCategory;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The five fields of the rule offcanvas. Whether the rule is a special period is not a
 * form field: it follows from the route that opened the offcanvas, so a half-filled date
 * pair can never turn an unlimited rule into a dated one behind the operator's back.
 */
final class BookingRestrictionRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => BookingRestrictionType::class,
                'label' => 'booking_rules.type',
                'choice_label' => static fn (BookingRestrictionType $type): string => 'booking_rules.type_'.$type->value,
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('weekdays', ChoiceType::class, [
                'label' => 'booking_rules.weekdays',
                'expanded' => true,
                'multiple' => true,
                'choices' => array_combine(
                    array_map(static fn (int $day): string => 'booking_rules.day_'.$day, range(1, 7)),
                    range(1, 7),
                ),
            ])
            ->add('minNights', IntegerType::class, [
                'label' => 'booking_rules.min_nights',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 365],
            ])
            ->add('allCategories', CheckboxType::class, [
                'label' => 'booking_rules.all_categories',
                'required' => false,
            ])
            ->add('categories', EntityType::class, [
                'class' => RoomCategory::class,
                'choice_label' => 'name',
                'label' => 'booking_rules.categories',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);

        if (true === $options['is_period']) {
            $builder
                ->add('startDate', DateType::class, [
                    'label' => 'booking_rules.first_date',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ])
                ->add('lastDate', DateType::class, [
                    'label' => 'booking_rules.last_date',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                    'required' => false,
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Its own token id keeps this form on session-bound CSRF rather than the app-wide
        // stateless "submit" id (config/packages/csrf.yaml). The offcanvas posts through a
        // plain fetch, which does not perform the cookie handshake stateless tokens need.
        $resolver
            ->setDefaults([
                'data_class' => RuleData::class,
                'csrf_token_id' => 'booking_restriction_rule',
                'is_period' => false,
            ])
            ->setAllowedTypes('is_period', 'bool');
    }
}
