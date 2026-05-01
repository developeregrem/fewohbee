<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BankCsvProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Logical target columns the user can map to a 0-based CSV column index.
 * Keys here must match the keys consumed by {@see \App\Service\BookingJournal\BankImport\Parser\GenericCsvParser}.
 */
class BankCsvProfileType extends AbstractType
{
    public const COLUMN_FIELDS = [
        'bookDate'         => 'accounting.bank_import.profile.col.book_date',
        'valueDate'        => 'accounting.bank_import.profile.col.value_date',
        'counterpartyName' => 'accounting.bank_import.profile.col.counterparty_name',
        'counterpartyIban' => 'accounting.bank_import.profile.col.counterparty_iban',
        'purpose'          => 'accounting.bank_import.profile.col.purpose',
        'amount'           => 'accounting.bank_import.profile.col.amount',
        'amountDebit'      => 'accounting.bank_import.profile.col.amount_debit',
        'amountCredit'     => 'accounting.bank_import.profile.col.amount_credit',
        'endToEndId'       => 'accounting.bank_import.profile.col.end_to_end_id',
        'mandateReference' => 'accounting.bank_import.profile.col.mandate_reference',
        'creditorId'       => 'accounting.bank_import.profile.col.creditor_id',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'accounting.bank_import.profile.name',
                'attr' => ['maxlength' => 100, 'placeholder' => 'DKB Girokonto'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'accounting.bank_import.profile.description',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
            ->add('delimiter', TextType::class, [
                'label' => 'accounting.bank_import.profile.delimiter',
                'attr' => ['maxlength' => 3, 'placeholder' => ';', 'class' => 'form-control-sm'],
            ])
            ->add('enclosure', TextType::class, [
                'label' => 'accounting.bank_import.profile.enclosure',
                'attr' => ['maxlength' => 1, 'placeholder' => '"', 'class' => 'form-control-sm'],
            ])
            ->add('encoding', ChoiceType::class, [
                'label' => 'accounting.bank_import.profile.encoding',
                'choices' => [
                    'UTF-8' => 'UTF-8',
                    'ISO-8859-15 (Westeuropa)' => 'ISO-8859-15',
                    'Windows-1252' => 'Windows-1252',
                ],
                'attr' => ['class' => 'form-select-sm'],
            ])
            ->add('headerSkip', IntegerType::class, [
                'label' => 'accounting.bank_import.profile.header_skip',
                'help' => 'accounting.bank_import.profile.header_skip.help',
                'attr' => ['min' => 0, 'max' => 50, 'class' => 'form-control-sm'],
            ])
            ->add('hasHeaderRow', CheckboxType::class, [
                'label' => 'accounting.bank_import.profile.has_header_row',
                'required' => false,
            ])
            ->add('dateFormat', TextType::class, [
                'label' => 'accounting.bank_import.profile.date_format',
                'help' => 'accounting.bank_import.profile.date_format.help',
                'attr' => ['maxlength' => 20, 'placeholder' => 'd.m.Y', 'class' => 'form-control-sm'],
            ])
            ->add('amountDecimalSeparator', TextType::class, [
                'label' => 'accounting.bank_import.profile.amount_decimal_separator',
                'attr' => ['maxlength' => 1, 'placeholder' => ',', 'class' => 'form-control-sm'],
            ])
            ->add('amountThousandsSeparator', TextType::class, [
                'label' => 'accounting.bank_import.profile.amount_thousands_separator',
                'required' => false,
                'attr' => ['maxlength' => 1, 'placeholder' => '.', 'class' => 'form-control-sm'],
            ])
            ->add('directionMode', ChoiceType::class, [
                'label' => 'accounting.bank_import.profile.direction_mode',
                'help' => 'accounting.bank_import.profile.direction_mode.help',
                'choices' => [
                    'accounting.bank_import.profile.direction.signed' => BankCsvProfile::DIRECTION_SIGNED,
                    'accounting.bank_import.profile.direction.separate_columns' => BankCsvProfile::DIRECTION_SEPARATE_COLUMNS,
                ],
                'attr' => ['class' => 'form-select-sm'],
            ])
            ->add('ibanSourceLine', IntegerType::class, [
                'label' => 'accounting.bank_import.profile.iban_source_line',
                'help' => 'accounting.bank_import.profile.iban_source_line.help',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 50, 'class' => 'form-control-sm'],
            ])
            ->add('periodSourceLine', IntegerType::class, [
                'label' => 'accounting.bank_import.profile.period_source_line',
                'help' => 'accounting.bank_import.profile.period_source_line.help',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 50, 'class' => 'form-control-sm'],
            ]);

        // Synthetic per-field column index inputs that are mapped to the JSON
        // columnMap field on submission.
        foreach (self::COLUMN_FIELDS as $key => $label) {
            $builder->add('col_'.$key, IntegerType::class, [
                'label' => $label,
                'required' => false,
                'mapped' => false,
                'attr' => ['min' => 0, 'max' => 100, 'class' => 'form-control-sm'],
            ]);
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            /** @var BankCsvProfile|null $profile */
            $profile = $event->getData();
            if (!$profile instanceof BankCsvProfile) {
                return;
            }

            $form = $event->getForm();
            foreach ($profile->getColumnMap() as $key => $index) {
                $child = 'col_'.$key;
                if ($form->has($child)) {
                    $form->get($child)->setData($index);
                }
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            /** @var BankCsvProfile|null $profile */
            $profile = $event->getData();
            if (!$profile instanceof BankCsvProfile) {
                return;
            }

            $form = $event->getForm();
            $columnMap = [];
            foreach (array_keys(self::COLUMN_FIELDS) as $key) {
                $value = $form->get('col_'.$key)->getData();
                if (null !== $value && '' !== $value) {
                    $columnMap[$key] = (int) $value;
                }
            }
            $profile->setColumnMap($columnMap);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BankCsvProfile::class,
        ]);
    }
}
