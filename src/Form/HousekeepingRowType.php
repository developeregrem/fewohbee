<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\HousekeepingStatus;
use App\Entity\RoomDayStatus;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Lightweight row form for editing housekeeping status entries.
 */
class HousekeepingRowType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', HiddenType::class, [
                'mapped' => false,
                'data' => $options['date'] ?? null,
            ])
            ->add('hkStatus', ChoiceType::class, [
                'label' => false,
                'choices' => HousekeepingStatus::cases(),
                'choice_value' => static fn (?HousekeepingStatus $status): ?string => $status?->value,
                'choice_label' => static fn (HousekeepingStatus $status): string => 'housekeeping.status.'.strtolower($status->value),
                'choice_translation_domain' => 'Housekeeping',
            ])
            ->add('assignedTo', EntityType::class, [
                'label' => false,
                'class' => User::class,
                'required' => false,
                'placeholder' => 'housekeeping.unassigned',
                'translation_domain' => 'Housekeeping',
                'choice_label' => static fn (User $user): string => trim(sprintf('%s %s', $user->getFirstname(), $user->getLastname())),
            ])
            ->add('note', TextType::class, [
                'label' => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RoomDayStatus::class,
            'csrf_token_id' => 'housekeeping_update',
            'date' => null,
        ]);
    }
}
