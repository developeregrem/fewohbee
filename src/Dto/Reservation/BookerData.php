<?php

declare(strict_types=1);

namespace App\Dto\Reservation;

/**
 * Contact data for the booker of a new reservation when no existing customer is referenced.
 */
final readonly class BookerData
{
    public function __construct(
        public string $lastname,
        public ?string $firstname = null,
        public ?string $salutation = null,
        public ?string $email = null,
        public ?string $phone = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'lastname' => $this->lastname,
            'firstname' => $this->firstname,
            'salutation' => $this->salutation,
            'email' => null !== $this->email ? mb_strtolower($this->email) : null,
            'phone' => $this->phone,
        ];
    }
}
