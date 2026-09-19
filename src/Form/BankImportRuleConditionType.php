<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\BankImportRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * One condition of a {@see BankImportRule}, with only its value editable —
 * enough to fix a typo in a counterparty name.
 *
 * Which field a condition looks at and which operator it uses stays as the
 * import preview recorded it: both are shown as a label next to the input.
 */
class BankImportRuleConditionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $condition = $event->getData();
            if (!is_array($condition)) {
                return;
            }

            // "between" keeps two bounds in an array. Nothing creates such a
            // condition today; leaving the field out renders it read-only
            // rather than flattening the bounds into one input.
            $value = $condition['value'] ?? null;
            if (!is_scalar($value)) {
                return;
            }

            $form = $event->getForm();
            if (BankImportRule::CONDITION_FIELD_DIRECTION === ($condition['field'] ?? '')) {
                $form->add('value', ChoiceType::class, [
                    'label' => false,
                    'choices' => [
                        'accounting.bank_import.rule.direction.in' => 'in',
                        'accounting.bank_import.rule.direction.out' => 'out',
                    ],
                ]);

                return;
            }

            $form->add('value', TextType::class, [
                'label' => false,
                // An empty "contains" would never match again, so the rule must
                // not be saved that way.
                'constraints' => [new NotBlank(), new Length(max: 255)],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
