<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

use App\Entity\Enum\IDCardType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The main guest on the check-in form. Lengths follow the Customer and CustomerAddresses
 * columns the data is taken over into; which fields are required depends on the settings
 * (GuestCheckInType), the constraints here only check the format.
 */
final class GuestCheckInGuest
{
    /** One of the salutations configured in the general settings (their untranslated key). */
    public ?string $salutation = null;

    #[Assert\Length(max: 45)]
    public ?string $firstname = null;

    #[Assert\Length(max: 45)]
    public ?string $lastname = null;

    #[Assert\Range(min: '1900-01-01', max: 'today')]
    public ?\DateTimeImmutable $birthday = null;

    #[Assert\Country]
    public ?string $nationality = null;

    public ?IDCardType $idType = null;

    #[Assert\Regex(pattern: '/^[A-Za-z0-9<\- ]{4,30}$/', message: 'guest_checkin.id_number_invalid')]
    public ?string $idNumber = null;

    #[Assert\Length(max: 150)]
    public ?string $street = null;

    #[Assert\Length(max: 10)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9 \-]{2,10}$/', message: 'guest_checkin.zip_invalid')]
    public ?string $zip = null;

    #[Assert\Length(max: 45)]
    public ?string $city = null;

    #[Assert\Country]
    public ?string $country = null;

    #[Assert\Email]
    #[Assert\Length(max: 100)]
    public ?string $email = null;

    #[Assert\Regex(pattern: '/^[0-9+()\/ .\-]{5,30}$/', message: 'guest_checkin.phone_invalid')]
    public ?string $phone = null;
}
