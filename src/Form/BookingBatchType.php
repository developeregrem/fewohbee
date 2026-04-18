<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BookingBatch;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingBatchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('year', IntegerType::class, [
                'label' => 'accounting.journal.batch.year',
            ])
            ->add('month', ChoiceType::class, [
                'label' => 'accounting.journal.batch.month',
                'choices' => array_combine(range(1, 12), range(1, 12)),
            ])
            ->add('cashStart', NumberType::class, [
                'label' => 'accounting.journal.cashbook.start',
                'help' => 'accounting.journal.cashbook.start_help',
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingBatch::class,
        ]);
    }
}
