<?php

declare(strict_types=1);

namespace App\Dto\PublicBooking;

/**
 * Contact payload the guest submits on the last wizard step.
 *
 * Purely a transport shape — every field arrives as a raw string and is validated
 * downstream when the customer record is built.
 */
final readonly class BookerInput
{
    public function __construct(
        public string $salutation,
        public string $firstname,
        public string $lastname,
        public string $email,
        public string $phone,
        public string $company,
        public string $address,
        public string $zip,
        public string $city,
        public string $country,
        public string $comment,
    ) {
    }

    /**
     * The booking service and the templates both consume the legacy array shape.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'salutation' => $this->salutation,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'address' => $this->address,
            'zip' => $this->zip,
            'city' => $this->city,
            'country' => $this->country,
            'comment' => $this->comment,
        ];
    }
}
