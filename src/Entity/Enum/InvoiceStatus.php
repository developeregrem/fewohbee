<?php

namespace App\Entity\Enum;

enum InvoiceStatus: int
{
    case OPEN = 1;
    case PREPAID = 3;
    case PAID = 2;
    case CANCELLED = 4;
}
