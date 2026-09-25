<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A fellow traveller on the check-in form. Lengths follow the Customer columns the data is
 * taken over into; which fields are required depends on the settings (GuestCheckInType).
 */
final class GuestCheckInCompanion
{
    #[Assert\Length(max: 45)]
    public ?string $firstname = null;

    #[Assert\Length(max: 45)]
    public ?string $lastname = null;

    #[Assert\Range(min: '1900-01-01', max: 'today')]
    public ?\DateTimeImmutable $birthday = null;

    #[Assert\Country]
    public ?string $nationality = null;

    /** Own address; all empty means the fellow traveller lives with the main guest. */
    #[Assert\Length(max: 150)]
    public ?string $street = null;

    #[Assert\Length(max: 10)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9 \-]{2,10}$/', message: 'guest_checkin.zip_invalid')]
    public ?string $zip = null;

    #[Assert\Length(max: 45)]
    public ?string $city = null;

    #[Assert\Country]
    public ?string $country = null;

    public function hasOwnAddress(): bool
    {
        return null !== $this->street || null !== $this->zip || null !== $this->city || null !== $this->country;
    }

    public function isEmpty(): bool
    {
        return null === $this->firstname && null === $this->lastname && null === $this->birthday && null === $this->nationality
            && !$this->hasOwnAddress();
    }
}
