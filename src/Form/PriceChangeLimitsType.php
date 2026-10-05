<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\PriceRounding;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Limits and rounding for prices changed by price rules. Both limits are entered as positive
 * numbers ("lower by at most 30 %"); the controller stores the lower one as a negative bound.
 */
final class PriceChangeLimitsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('maxDecrease', IntegerType::class, [
                'label' => 'price_rules.limits.max_decrease',
                'attr' => ['min' => 0, 'max' => 99],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 99)],
            ])
            ->add('maxIncrease', IntegerType::class, [
                'label' => 'price_rules.limits.max_increase',
                'attr' => ['min' => 0, 'max' => 500],
                'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 500)],
            ])
            ->add('rounding', EnumType::class, [
                'class' => PriceRounding::class,
                'label' => 'price_rules.limits.rounding',
                'choice_label' => static fn (PriceRounding $rounding): string => 'price_rules.limits.rounding_'.$rounding->value,
                'expanded' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'price_change_limits']);
    }
}
