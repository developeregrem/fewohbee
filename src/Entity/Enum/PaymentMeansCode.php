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

    /** Font Awesome icon that stands for the payment means, e.g. in the invoice list. */
    public function icon(): string
    {
        return match ($this) {
            self::CASH => 'fa-coins',
            self::CREDIT_TRANSFER, self::SEPA_CREDIT_TRANSFER => 'fa-building-columns',
            self::CARD_PAYMENT => 'fa-credit-card',
            self::SEPA_DIRECT_DEBIT => 'fa-money-check',
        };
    }
}
