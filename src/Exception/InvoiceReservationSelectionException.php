<?php

declare(strict_types=1);

namespace App\Exception;

/** An invoice draft refers to a reservation that is no longer available. */
final class InvoiceReservationSelectionException extends \DomainException
{
}
