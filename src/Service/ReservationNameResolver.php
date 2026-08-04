<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarSyncImport;
use App\Entity\Customer;
use App\Entity\Reservation;

/**
 * Resolves a display name for a reservation from booker/import data.
 * Single source of truth for the "business company vs. personal name vs. import name" rule.
 */
class ReservationNameResolver
{
    public function resolve(Reservation $reservation): string
    {
        $booker = $reservation->getBooker();
        if ($booker instanceof Customer) {
            $business = $this->resolveBusinessCompany($booker);
            if (null !== $business) {
                $lastname = trim((string) $booker->getLastname());
                if ('' !== $lastname) {
                    return sprintf('%s (%s)', $business, $lastname);
                }

                return $business;
            }

            return trim(sprintf('%s %s', (string) $booker->getLastname(), (string) $booker->getFirstname()));
        }

        $import = $reservation->getCalendarSyncImport();
        if ($import instanceof CalendarSyncImport) {
            $name = trim($import->getName());
            if ('' !== $name) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Resolve the business company name for a customer if available.
     */
    public function resolveBusinessCompany(Customer $customer): ?string
    {
        foreach ($customer->getCustomerAddresses() as $address) {
            if ('CUSTOMER_ADDRESS_TYPE_BUSINESS' === $address->getType()) {
                $company = trim((string) $address->getCompany());
                if ('' !== $company) {
                    return $company;
                }
            }
        }

        return null;
    }
}
