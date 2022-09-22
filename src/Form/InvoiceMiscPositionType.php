<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\InvoicePosition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InvoiceMiscPositionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', IntegerType::class, [
                'label' => 'invoice.miscellaneous.position.amount',
            ])
            ->add('description', TextType::class, ['label' => 'invoice.appartment.position.description'])
            ->add('price', NumberType::class, [
                'label' => 'invoice.appartment.position.price',
                'scale' => 2,
            ])
            ->add('vat', NumberType::class, [
                'label' => 'invoice.vat',
                'scale' => 2,
            ])
            ->add('includesVat', CheckboxType::class, [
                'label' => 'price.includesvat',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
            ->add('isFlatPrice', CheckboxType::class, [
                'label' => 'price.isflatprice',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoicePosition::class,
        ]);
    }
}
