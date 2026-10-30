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

/**
 * Reservation draft stored in the wizard session. ID values keep their form representation;
 * periods are date strings validated by ReservationPeriodService.
 */
class ReservationObject
{
    private int|string $appartmentId;
    private string $start;
    private string $end;
    private int|string|null $reservationStatus;
    private int $persons;
    private int|string|null $customerId = null;
    /** @var array<int, int> */
    private array $guestCounts = [];
    private bool $adultRuleOverride = false;
    private bool $kurtaxeWaived = false;

    public function __construct(
        int|string $appartmentId,
        string $start,
        string $end,
        int|string|null $status,
        int $persons,
    ) {
        $this->appartmentId = $appartmentId;
        $this->start = $start;
        $this->end = $end;
        $this->reservationStatus = $status;
        $this->persons = $persons;
    }

    public function getCustomerId(): int|string|null
    {
        return $this->customerId;
    }

    public function setCustomerId(int|string|null $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getAppartmentId(): int|string
    {
        return $this->appartmentId;
    }

    public function getStart(): string
    {
        return $this->start;
    }

    public function getEnd(): string
    {
        return $this->end;
    }

    public function getReservationStatus(): int|string|null
    {
        return $this->reservationStatus;
    }

    public function getPersons(): int
    {
        return $this->persons;
    }

    public function setReservationStatus(int|string|null $status): void
    {
        $this->reservationStatus = $status;
    }

    public function setPersons(int $persons): void
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
     * Normalize integer and numeric-string counts, discarding non-positive values.
     *
     * @param array<int, int|numeric-string> $guestCounts
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
