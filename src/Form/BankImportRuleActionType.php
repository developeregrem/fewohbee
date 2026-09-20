<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BankImportRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The action of a {@see BankImportRule}, editable down to the values it already
 * carries.
 *
 * Only fields the stored action actually has are added, so the action mode,
 * the number of split rows and the invoice-extraction mode survive a save
 * untouched: whatever no field is built for is left in the array as it is.
 */
class BankImportRuleActionType extends AbstractType
{
    public function __construct(
        private readonly BankImportRuleFormFields $fields,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options): void {
            $action = $event->getData();
            if (!is_array($action)) {
                return;
            }

            $form = $event->getForm();
            $mode = (string) ($action['mode'] ?? BankImportRule::ACTION_MODE_IGNORE);

            if (BankImportRule::ACTION_MODE_ASSIGN === $mode) {
                $this->fields->addBookingFields($form, $options['account_choices'], $options['tax_rate_choices']);
            } elseif (BankImportRule::ACTION_MODE_SPLIT === $mode) {
                $form->add('splits', CollectionType::class, [
                    'label' => false,
                    'entry_type' => BankImportRuleSplitType::class,
                    'entry_options' => [
                        'label' => false,
                        'account_choices' => $options['account_choices'],
                        'tax_rate_choices' => $options['tax_rate_choices'],
                    ],
                    'allow_add' => false,
                    'allow_delete' => false,
                ]);
            }

            $extraction = $action['invoiceNumberExtraction'] ?? null;
            if (is_array($extraction) && in_array($extraction['mode'] ?? 'none', ['marker', 'regex'], true)) {
                $form->add('invoiceNumberExtraction', BankImportRuleInvoiceExtractionType::class, [
                    'label' => false,
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
        $resolver->setRequired(['account_choices', 'tax_rate_choices']);
        $resolver->setAllowedTypes('account_choices', 'array');
        $resolver->setAllowedTypes('tax_rate_choices', 'array');
    }
}
