<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'special_days')]
class SpecialDays
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;
    #[ORM\Column(type: 'time')]
    private $start;
    #[ORM\Column(type: 'time')]
    private $end;
    #[ORM\Column(type: 'string', length: 45)]
    private $name;
}
