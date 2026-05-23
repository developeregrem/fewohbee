<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum LogAction: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
}
