<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity @ORM\Table(name="special_days")
 **/

class SpecialDays
{
    /** @ORM\Id @ORM\Column(type="integer") @ORM\GeneratedValue * */
    private $special_days_id;

    /** @ORM\Column(type="time") * */
    private $start;

    /** @ORM\Column(type="time") * */
    private $end;

    /** @ORM\Column(type="string", length=45) * */
    private $name;
}