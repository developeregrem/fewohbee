<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\BookingJournal\BankImport\UserRegexCompiler;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Field definitions the rule editor needs in more than one place.
 *
 * The four booking fields appear once for an "assign" action and once per row
 * of a "split" action; the regex check guards both the split patterns and the
 * invoice-number pattern. Defining them here keeps the variants in sync.
 */
final class BankImportRuleFormFields
{
    public function __construct(
        private readonly UserRegexCompiler $regexCompiler,
    ) {
    }

    /**
     * Adds the accounts, tax rate and remark template a rule writes onto a line.
     *
     * @param array<string, int> $accountChoices label => account id
     * @param array<string, int> $taxRateChoices label => tax rate id
     */
    public function addBookingFields(FormInterface $form, array $accountChoices, array $taxRateChoices): void
    {
        $form
            ->add('debitAccountId', ChoiceType::class, [
                'label' => 'accounting.bank_import.preview.col.debit',
                'choices' => $accountChoices,
                // Account and tax rate labels are data, not translation keys.
                'choice_translation_domain' => false,
                'placeholder' => '—',
                'required' => false,
            ])
            ->add('creditAccountId', ChoiceType::class, [
                'label' => 'accounting.bank_import.preview.col.credit',
                'choices' => $accountChoices,
                'choice_translation_domain' => false,
                'placeholder' => '—',
                'required' => false,
            ])
            ->add('taxRateId', ChoiceType::class, [
                'label' => 'accounting.bank_import.preview.col.tax_rate',
                'choices' => $taxRateChoices,
                'choice_translation_domain' => false,
                'placeholder' => '—',
                'required' => false,
            ])
            ->add('remarkTemplate', TextType::class, [
                'label' => 'accounting.bank_import.rule.remark_template',
                'help' => 'accounting.bank_import.rule.remark_template.help',
                'required' => false,
                'empty_data' => null,
                'constraints' => [new Length(max: 255)],
            ])
        ;
    }

    /**
     * Rejects a pattern preg_* cannot use. Without it a typo would only show up
     * as a warning during the next import.
     */
    public function regexConstraint(): Callback
    {
        return new Callback(function (?string $value, ExecutionContextInterface $context): void {
            if (null === $value || '' === $value || $this->regexCompiler->isValid($value)) {
                return;
            }

            $context->buildViolation('accounting.bank_import.rules.validation.regex_invalid')->addViolation();
        });
    }
}
