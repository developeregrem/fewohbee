<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Payment method, payment due date and remark of an invoice.
 *
 * Only the resulting due date is stored. A period in days or the last departure is a way
 * of entering it and is turned into a date on submit, so the date on an issued invoice
 * stays put when its date or positions change later.
 */
class InvoicePaymentRemarkType extends AbstractType
{
    private const MODE_SETTINGS = 'settings';
    private const MODE_DAYS = 'days';
    private const MODE_LAST_DEPARTURE = 'last_departure';
    private const MODE_DATE = 'date';
    private const MAX_DAYS = 365;

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $settingsDueDays = $options['settings_due_days'];
        $lastDeparture = $options['last_departure'];

        $builder
            ->add('paymentMeans', EnumType::class, [
                'class' => PaymentMeansCode::class,
                'label' => 'invoice.paymentmeans.label',
                'required' => false,
            ])
            ->add('paymentDueMode', ChoiceType::class, [
                'mapped' => false,
                'expanded' => true,
                'label' => 'invoice.payment_due.label',
                'choices' => [
                    null === $settingsDueDays ? 'invoice.payment_due.mode.settings.none' : 'invoice.payment_due.mode.settings' => self::MODE_SETTINGS,
                    'invoice.payment_due.mode.days' => self::MODE_DAYS,
                    'invoice.payment_due.mode.last_departure' => self::MODE_LAST_DEPARTURE,
                    'invoice.payment_due.mode.date' => self::MODE_DATE,
                ],
                // The settings choice names the period it stands for, so nobody has to look it up.
                'choice_translation_parameters' => static fn (string $mode): array => self::MODE_SETTINGS === $mode && null !== $settingsDueDays
                    ? ['%count%' => $settingsDueDays]
                    : [],
                'choice_attr' => static fn (string $mode): array => [
                    'data-action' => 'change->invoices#togglePaymentDueFieldsAction',
                ] + (self::MODE_LAST_DEPARTURE === $mode && null === $lastDeparture ? ['disabled' => 'disabled'] : []),
                'help' => 'invoice.payment_due.help',
            ])
            ->add('paymentDueDays', IntegerType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'invoice.payment_due.days',
                'attr' => ['min' => 0, 'max' => self::MAX_DAYS],
            ])
            ->add('paymentDueDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'label' => 'invoice.payment_due.date',
            ])
            ->add('remark', TextareaType::class, [
                'label' => 'invoice.remark',
                'required' => false,
            ])
        ;

        // An invoice with a date of its own reopens on that date; everything else on the settings.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $invoice = $event->getData();
            $hasOwnDate = $invoice instanceof Invoice && null !== $invoice->getPaymentDueDate();
            $event->getForm()->get('paymentDueMode')->setData($hasOwnDate ? self::MODE_DATE : self::MODE_SETTINGS);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use ($lastDeparture): void {
            $form = $event->getForm();
            $invoice = $form->getData();
            if ($invoice instanceof Invoice) {
                $this->applyDueMode($form, $invoice, $lastDeparture);
            }
        });
    }

    /**
     * Turns the chosen way of entry into the stored due date. Errors are attached here
     * rather than as constraints because they depend on unmapped fields.
     */
    private function applyDueMode(FormInterface $form, Invoice $invoice, ?\DateTimeInterface $lastDeparture): void
    {
        $invoiceDate = \DateTimeImmutable::createFromInterface($invoice->getDate())->setTime(0, 0);

        switch ($form->get('paymentDueMode')->getData()) {
            case self::MODE_DAYS:
                $days = $form->get('paymentDueDays')->getData();
                if (!\is_int($days) || $days < 0 || $days > self::MAX_DAYS) {
                    $this->addError($form->get('paymentDueDays'), 'invoice.payment_due.days.required', ['%max%' => self::MAX_DAYS]);

                    return;
                }
                $invoice->setPaymentDueDate($invoiceDate->modify('+'.$days.' days'));
                break;

            case self::MODE_LAST_DEPARTURE:
                if (null === $lastDeparture) {
                    $this->addError($form->get('paymentDueMode'), 'invoice.payment_due.no_departure');

                    return;
                }
                // An invoice written after the stay is due at once, not on a day already past.
                $invoice->setPaymentDueDate(max(\DateTimeImmutable::createFromInterface($lastDeparture), $invoiceDate));
                break;

            case self::MODE_DATE:
                // The date field is mapped and has already been written to the invoice.
                if (null === $invoice->getPaymentDueDate()) {
                    $this->addError($form->get('paymentDueDate'), 'invoice.payment_due.date.required');
                }
                break;

            default:
                $invoice->setPaymentDueDate(null);
        }
    }

    /** @param array<string, int> $parameters */
    private function addError(FormInterface $field, string $key, array $parameters = []): void
    {
        $field->addError(new FormError($this->translator->trans($key, $parameters, 'validators'), $key, $parameters));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invoice::class,
            // The issuer's payment period, shown on the settings choice; null when it has none.
            'settings_due_days' => null,
            // Latest departure among the apartment positions; null disables that choice.
            'last_departure' => null,
        ]);
        $resolver->setAllowedTypes('settings_due_days', ['null', 'int']);
        $resolver->setAllowedTypes('last_departure', ['null', \DateTimeInterface::class]);
    }
}
