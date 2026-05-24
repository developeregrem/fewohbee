<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
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
    private $reservationStatus;
    private $persons;
    private $customerId;
    /** @var array<int, int> */
    private array $guestCounts = [];
    private bool $adultRuleOverride = false;
    private bool $kurtaxeWaived = false;

    public function __construct($appartmentId, $start, $end, $status, $persons)
    {
        $this->appartmentId = $appartmentId;
        $this->start = $start;
        $this->end = $end;
        $this->reservationStatus = $status;
        $this->persons = $persons;
    }

    public function getCustomerId()
    {
        return $this->customerId;
    }

    public function setCustomerId($customerId): void
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

    public function getReservationStatus()
    {
        return $this->reservationStatus;
    }

    public function getPersons()
    {
        return $this->persons;
    }

    public function setReservationStatus($status): void
    {
        $this->reservationStatus = $status;
    }

    public function setPersons($persons): void
    {
        $this->persons = $persons;
    }

    /**
     * @return array<int, int>
     */
    public function getGuestCounts(): array
    {
        return $this->guestCounts;
    }

    /**
     * @param array<int, int> $guestCounts
     */
    public function setGuestCounts(array $guestCounts): void
    {
        $normalized = [];
        foreach ($guestCounts as $categoryId => $count) {
            $count = (int) $count;
            if ($count > 0) {
                $normalized[(int) $categoryId] = $count;
            }
        }
        $this->guestCounts = $normalized;
    }

    public function isAdultRuleOverride(): bool
    {
        return $this->adultRuleOverride;
    }

    public function setAdultRuleOverride(bool $adultRuleOverride): void
    {
        $this->adultRuleOverride = $adultRuleOverride;
    }

    public function isKurtaxeWaived(): bool
    {
        return $this->kurtaxeWaived;
    }

    public function setKurtaxeWaived(bool $kurtaxeWaived): void
    {
        $this->kurtaxeWaived = $kurtaxeWaived;
    }
}
