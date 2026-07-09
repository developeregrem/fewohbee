<?php

declare(strict_types=1);

namespace App\Service\EInvoice\Validation;

// Thrown when e-invoice generation is attempted with an invalid invoice/settings combination.
final class EInvoiceValidationException extends \RuntimeException
{
    public function __construct(public readonly EInvoiceValidationResult $result)
    {
        parent::__construct('E-invoice validation failed: '.implode(', ', $result->getMessageKeys()));
    }
}
