<?php

namespace App\Entity\Enum;

enum IDCardType: string
{
    case DRIVING_LICENCE = 'customer.id.type.driving_licence';
    case NATIONAL_ID = 'customer.id.type.national_id';
    case PASSPORT = 'customer.id.type.passport';
}
