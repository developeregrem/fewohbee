<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Invoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InvoiceCustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('salutation', TextType::class, [
                'label' => 'customer.salutation',
//                'required' => false,
            ])
            ->add('firstname', TextType::class, [
                'label' => 'customer.firstname',
//                'required' => false,
            ])
            ->add('lastname', TextType::class, [
                'label' => 'customer.lastname',
//                'required' => false,
            ])
            ->add('company', TextType::class, [
                'label' => 'customer.company',
//                'required' => false,
            ])
            ->add('address', TextType::class, [
                'label' => 'customer.address',
//                'required' => false,
            ])
            ->add('zip', TextType::class, [
                'label' => 'customer.zip',
//                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'customer.city',
//                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invoice::class,
        ]);
    }
}
