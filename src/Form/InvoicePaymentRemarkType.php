<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enum\PaymentMeansCode;
use App\Entity\Invoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Payment method, payment due date and remark of an invoice.
 *
 * The due date is the only stored value. The template offers two helpers next to it -
 * a number of days from the invoice date and the last departure - which only fill in
 * the date in the browser and are never submitted, so they cannot block saving.
 */
class InvoicePaymentRemarkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $defaultDueDate = $options['default_due_date'];

        $builder
            ->add('paymentMeans', EnumType::class, [
                'class' => PaymentMeansCode::class,
                'label' => 'invoice.paymentmeans.label',
                'required' => false,
            ])
            ->add('paymentDueDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'invoice.payment_due.date',
                // Without a period in the settings an invoice may go without a due date.
                'required' => null !== $defaultDueDate,
                'constraints' => null !== $defaultDueDate ? [new NotNull(message: 'invoice.payment_due.date.required')] : [],
            ])
            ->add('remark', TextareaType::class, [
                'label' => 'invoice.remark',
                'required' => false,
            ])
        ;

        // An invoice still being created has no date yet and starts with the settings' one.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event) use ($defaultDueDate): void {
            $invoice = $event->getData();
            if ($invoice instanceof Invoice && null === $invoice->getPaymentDueDate() && null !== $defaultDueDate) {
                $event->getForm()->get('paymentDueDate')->setData($defaultDueDate);
            }
        });
    }

    /**
     * Hands the helpers their reference dates. The last departure is never earlier than
     * the invoice date: an invoice written after the stay is due at once.
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $invoice = $form->getData();
        $invoiceDate = $invoice instanceof Invoice
            ? \DateTimeImmutable::createFromInterface($invoice->getDate())->setTime(0, 0)
            : null;
        $lastDeparture = $options['last_departure'];
        if (null !== $lastDeparture && null !== $invoiceDate) {
            $lastDeparture = max(\DateTimeImmutable::createFromInterface($lastDeparture), $invoiceDate);
        }

        $view->vars['payment_due_invoice_date'] = $invoiceDate?->format('Y-m-d');
        $view->vars['payment_due_last_departure'] = $lastDeparture?->format('Y-m-d');
    }

    /** Starts the days helper at the distance between invoice date and due date. */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['payment_due_days'] = null;
        $invoiceDate = $view->vars['payment_due_invoice_date'];
        $dueDate = $view->children['paymentDueDate']->vars['value'] ?? null;
        if (null === $invoiceDate || !\is_string($dueDate) || '' === $dueDate) {
            return;
        }
        $days = (int) (new \DateTimeImmutable($invoiceDate))->diff(new \DateTimeImmutable($dueDate))->format('%r%a');
        $view->vars['payment_due_days'] = $days >= 0 ? $days : null;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Invoice::class,
            // Invoice date plus the issuer's period; null when the issuer states none.
            'default_due_date' => null,
            // Latest departure among the apartment positions; null hides that helper.
            'last_departure' => null,
        ]);
        $resolver->setAllowedTypes('default_due_date', ['null', \DateTimeInterface::class]);
        $resolver->setAllowedTypes('last_departure', ['null', \DateTimeInterface::class]);
    }
}
