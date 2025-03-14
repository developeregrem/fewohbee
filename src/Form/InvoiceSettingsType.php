<?php

namespace App\Form;

use App\Entity\InvoiceSettingsData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Validator\Constraints\Bic;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Country;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class InvoiceSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('companyName', TextType::class, [
                'label' => 'invoice.settings.companyName',
            ])
            ->add('taxNumber', TextType::class, [
                'label' => 'invoice.settings.taxNumber',
                'required' => false,
            ])
            ->add('vatID', TextType::class, [
                'label' => 'invoice.settings.vatID',
                'required' => false,
                'constraints' => [
                    new Callback([$this, 'validateVatIDCountry'])
                ]
            ])
            ->add('contactName', TextType::class, [
                'label' => 'invoice.settings.contactName',
            ])
            ->add('contactDepartment', TextType::class, [
                'label' => 'invoice.settings.contactDepartment',
                'required' => false,
            ])
            ->add('contactPhone', TextType::class, [
                'label' => 'invoice.settings.contactPhone',
            ])
            ->add('contactMail', TextType::class, [
                'label' => 'invoice.settings.contactMail',
                'constraints' => [
                    new Email()
                ]
            ])
            ->add('companyInvoiceMail', TextType::class, [
                'label' => 'invoice.settings.companyInvoiceMail',
                'help' => 'invoice.settings.help.invoiceMail',
                'constraints' => [
                    new Email()
                ]
            ])
            ->add('companyAddress', TextType::class, [
                'label' => 'invoice.settings.companyAddress',
            ])
            ->add('companyPostCode', TextType::class, [
                'label' => 'invoice.settings.companyPostCode',
            ])
            ->add('companyCity', TextType::class, [
                'label' => 'invoice.settings.companyCity',
            ])
            ->add('companyCountry', CountryType::class, [
                'label' => 'invoice.settings.companyCountry',
            ])
            ->add('accountName', TextType::class, [
                'label' => 'invoice.settings.accountName',
            ])
            ->add('accountIBAN', TextType::class, [
                'label' => 'invoice.settings.accountIBAN',
                'constraints' => [
                    new Iban()
                ]
            ])
            ->add('accountBIC', TextType::class, [
                'label' => 'invoice.settings.accountBIC',
                'constraints' => [
                    new Bic()
                ],
                'required' => false,
            ])
            ->add('paymentTerms', TextareaType::class, [
                'label' => 'invoice.settings.paymentTerms',
                'required' => false,
            ])
            ->add('paymentDueDays', IntegerType::class, [
                'label' => 'invoice.settings.paymentDueDays',
                'required' => false,
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'invoice.settings.active',
                'label_attr' => ['class' => 'checkbox-inline checkbox-switch'],
                'required' => false,
            ])
        ;
    }

    public function validateVatIDCountry($vatID, ExecutionContextInterface $context): void
    {
        if ($vatID) {
            $countryCode = substr($vatID, 0, 2);
            $countryConstraint = new Country();
            $violations = $context->getValidator()->validate($countryCode, $countryConstraint);

            if (count($violations) > 0) {
                $context->buildViolation('form.vatid.invalid_country')
                    ->atPath('vatID')
                    ->addViolation();
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvoiceSettingsData::class,
        ]);
    }
}
