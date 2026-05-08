<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\GuestCategory;
use App\Entity\TouristTaxRate;
use App\Repository\GuestCategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TouristTaxRateType extends AbstractType
{
    public function __construct(
        private readonly GuestCategoryRepository $guestCategoryRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('guestCategory', EntityType::class, [
                'class' => GuestCategory::class,
                'choice_label' => 'name',
                'choices' => $this->guestCategoryRepository->findActiveOrdered(),
                'label' => false,
                'placeholder' => '-',
            ])
            ->add('pricePerNight', NumberType::class, [
                'label' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
            ->add('reportGroup', TextType::class, [
                'label' => false,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TouristTaxRate::class,
        ]);
    }
}
