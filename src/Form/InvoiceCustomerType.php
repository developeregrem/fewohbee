<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Invoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Iban;

class InvoiceCustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('salutation', TextType::class, [
                'label' => 'customer.salutation',
                'required' => false,
            ])
            ->add('firstname', TextType::class, [
                'label' => 'customer.firstname',
                'required' => false,
            ])
            ->add('lastname', TextType::class, [
                'label' => 'customer.lastname',
                'required' => false,
            ])
            ->add('company', TextType::class, [
                'label' => 'customer.company',
                'required' => false,
            ])
            ->add('address', TextType::class, [
                'label' => 'customer.address',
                'required' => false,
            ])
            ->add('zip', TextType::class, [
                'label' => 'customer.zip',
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'customer.city',
                'required' => false,
            ])
            ->add('country', CountryType::class, [
                'label' => 'customer.country',
            ])
            ->add('phone', TextType::class, [
                'label' => 'invoice.settings.contactPhone',
                'required' => false,
            ])
            ->add('email', TextType::class, [
                'label' => 'customer.email',
            ])
            ->add('cardHolder', TextType::class, [
                'label' => 'customer.cardHolder.label',
                'help' => 'customer.cardHolder.hint',
                'required' => false,
            ])
            ->add ('cardNumber', TextType::class, [
                'label' => 'customer.cardNumber',
                'required' => false,
            ])
            ->add('customerIBAN', TextType::class, [
                'label' => 'customer.accountIBAN.label',
                'help' => 'customer.accountIBAN.hint',
                'required' => false,
                'constraints' => [
                    new Iban()
                ]
            ])
            ->add('mandateReference', TextType::class, [
                'label' => 'customer.mandateReference.label',
                'required' => false,
            ])
            ->add('buyerReference', TextType::class, [
                'label' => 'customer.buyerReference.label',
                'help' => 'customer.buyerReference.hint',
                'required' => false,
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
