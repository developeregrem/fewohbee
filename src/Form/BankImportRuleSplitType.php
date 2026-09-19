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
 * One row of a "split" action: its booking fields plus the text a dynamic
 * amount is read from.
 *
 * A row with a fixed amount, a percentage or the remainder has no such text,
 * so only the booking fields are editable there. How many rows a rule has, and
 * how each one determines its amount, stays as recorded in the import preview.
 */
class BankImportRuleSplitType extends AbstractType
{
    public function __construct(
        private readonly BankImportRuleFormFields $fields,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($options): void {
            $split = $event->getData();
            if (!is_array($split)) {
                return;
            }

            $form = $event->getForm();
            $amountSource = $split['amountSource'] ?? null;

            if ('purpose_marker' === $amountSource) {
                $form->add('marker', TextType::class, [
                    'label' => 'accounting.bank_import.rule.split_source.purpose_marker',
                    'constraints' => [new NotBlank(), new Length(max: 120)],
                ]);
            } elseif ('purpose_regex' === $amountSource) {
                $form->add('pattern', TextType::class, [
                    'label' => 'accounting.bank_import.rule.split_source.purpose_regex',
                    'constraints' => [new NotBlank(), new Length(max: 120), $this->fields->regexConstraint()],
                ]);
            }

            $this->fields->addBookingFields($form, $options['account_choices'], $options['tax_rate_choices']);
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
