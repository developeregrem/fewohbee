<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AccountingAccount;
use App\Entity\BookingEntry;
use App\Entity\TaxRate;
use App\Repository\AccountingAccountRepository;
use App\Repository\TaxRateRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BookingEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $cashbookMode = $options['cashbook_mode'];
        $referenceDate = $options['reference_date'];

        $builder
            ->add('date', DateType::class, [
                'label' => 'accounting.journal.entry.date',
                'widget' => 'single_text',
            ])
            ->add('documentNumber', IntegerType::class, [
                'label' => 'accounting.journal.entry.doc_number',
            ])
            ->add('amount', NumberType::class, [
                'label' => 'accounting.journal.entry.amount',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0'],
            ]);

        if ($cashbookMode) {
            $builder
                ->add('direction', ChoiceType::class, [
                    'label' => 'accounting.journal.cashbook.direction',
                    'mapped' => false,
                    'expanded' => true,
                    'choices' => [
                        'accounting.journal.cashbook.incomes' => 'income',
                        'accounting.journal.cashbook.expenses' => 'expense',
                    ],
                    'data' => $options['direction_default'],
                ])
                ->add('category', EntityType::class, [
                    'label' => 'accounting.journal.cashbook.category',
                    'mapped' => false,
                    'class' => AccountingAccount::class,
                    'choice_label' => 'label',
                    'query_builder' => fn (AccountingAccountRepository $repo) => $repo->createNonCashQueryBuilder(),
                    'placeholder' => '-',
                    'data' => $options['category_default'],
                ]);
        } else {
            $builder
                ->add('debitAccount', EntityType::class, [
                    'label' => 'accounting.journal.entry.debit',
                    'class' => AccountingAccount::class,
                    'choice_label' => 'label',
                    'required' => false,
                    'placeholder' => '-',
                ])
                ->add('creditAccount', EntityType::class, [
                    'label' => 'accounting.journal.entry.credit',
                    'class' => AccountingAccount::class,
                    'choice_label' => 'label',
                    'required' => false,
                    'placeholder' => '-',
                ]);
        }

        $builder
            ->add('taxRate', EntityType::class, [
                'label' => 'accounting.journal.entry.tax_rate',
                'class' => TaxRate::class,
                'query_builder' => fn (TaxRateRepository $repo) => $repo->createValidAtQueryBuilder($referenceDate),
                'choice_label' => fn (TaxRate $rate) => $rate->getName().' ('.number_format($rate->getRateFloat(), 2, ',', '.').'%)',
                'required' => false,
                'placeholder' => '-',
            ])
            ->add('invoiceNumber', TextType::class, [
                'label' => 'accounting.journal.entry.invoice',
                'required' => false,
                'attr' => ['maxlength' => 50],
            ])
            ->add('remark', TextType::class, [
                'label' => 'accounting.journal.entry.remark',
                'required' => false,
                'attr' => ['maxlength' => 255],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BookingEntry::class,
            'reference_date' => new \DateTime(),
            'cashbook_mode' => false,
            'direction_default' => 'income',
            'category_default' => null,
        ]);
    }
}
