<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The marker or pattern a rule reads an external invoice number with.
 *
 * Only the text is editable — switching the mode itself, or turning the
 * extraction on and off, happens in the import preview.
 */
class BankImportRuleInvoiceExtractionType extends AbstractType
{
    public function __construct(
        private readonly BankImportRuleFormFields $fields,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $extraction = $event->getData();
            if (!is_array($extraction)) {
                return;
            }

            $form = $event->getForm();
            $mode = (string) ($extraction['mode'] ?? 'none');

            if ('marker' === $mode) {
                $form->add('marker', TextType::class, [
                    'label' => 'accounting.bank_import.rule.invoice_extraction.mode.marker',
                    'constraints' => [new NotBlank(), new Length(max: 120)],
                ]);

                return;
            }

            if ('regex' === $mode) {
                $form->add('pattern', TextType::class, [
                    'label' => 'accounting.bank_import.rule.invoice_extraction.mode.regex',
                    'constraints' => [new NotBlank(), new Length(max: 255), $this->fields->regexConstraint()],
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
