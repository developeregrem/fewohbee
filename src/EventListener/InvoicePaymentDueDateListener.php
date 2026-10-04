<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Invoice;
use App\Service\EInvoice\EInvoiceReadinessService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Gives a new invoice the due date of its issuer's payment period unless one was set.
 *
 * The creation form already fills it in; this covers every other way an invoice is
 * created, so no invoice is left to follow the settings later on.
 */
#[AsEntityListener(event: Events::prePersist, entity: Invoice::class)]
final class InvoicePaymentDueDateListener
{
    public function __construct(private readonly EInvoiceReadinessService $settingsResolver)
    {
    }

    public function prePersist(Invoice $invoice): void
    {
        if (null !== $invoice->getPaymentDueDate()) {
            return;
        }

        $invoice->setPaymentDueDate($invoice->defaultPaymentDueDate($this->settingsResolver->resolveSettingsFor($invoice)));
    }
}
