<?php

namespace App\Entity\Enum;

/**
 * Class representing the Payment means based on UNTDID 4461.
 */
enum PaymentMeansCode: int
{
    case CASH = 10;
    // case CHEQUE = 20;
    case CREDIT_TRANSFER = 30;  // non-sepa, like 58 but BIC is required
    case CARD_PAYMENT = 48;
    // case CREDIT_CARD_PAYMENT = 54;
    case SEPA_CREDIT_TRANSFER = 58; // like 30 but BIC is optional, Überweisung
    case SEPA_DIRECT_DEBIT = 59;    // Bankeinzug
}
