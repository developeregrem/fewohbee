<?php

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <alex.pensionsverwaltung@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

class ReservationObject
{
    private $appartmentId;
    private $start;
    private $end;
    private $status;
    private $persons;
    private $customerId;

    public function __construct($appartmentId, $start, $end, $status, $persons)
    {
        $this->appartmentId = $appartmentId;
        $this->start = $start;
        $this->end = $end;
        $this->status = $status;
        $this->persons = $persons;
    }

    public function getCustomerId()
    {
        return $this->customerId;
    }

    public function setCustomerId($customerId)
    {
        $this->customerId = $customerId;
    }

    public function getAppartmentId()
    {
        return $this->appartmentId;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getPersons()
    {
        return $this->persons;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function setPersons($persons)
    {
        $this->persons = $persons;
    }
}
