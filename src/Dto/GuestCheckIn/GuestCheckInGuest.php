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

    /** Invoice to a company; the other company fields only make sense together with a name. */
    #[Assert\Length(max: 255)]
    public ?string $company = null;

    /** Starts with the country code, e.g. DE123456789 or CHE-123.456.789 MWST. */
    #[Assert\Length(max: 50)]
    #[Assert\Regex(pattern: '/^[A-Za-z]{2}[A-Za-z0-9 +*.\-]{2,20}$/', message: 'guest_checkin.vat_id_invalid')]
    public ?string $vatId = null;

    /** Invoice address of the company; all empty means the address of the main guest. */
    #[Assert\Length(max: 150)]
    public ?string $companyStreet = null;

    #[Assert\Length(max: 10)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9 \-]{2,10}$/', message: 'guest_checkin.zip_invalid')]
    public ?string $companyZip = null;

    #[Assert\Length(max: 45)]
    public ?string $companyCity = null;

    #[Assert\Country]
    public ?string $companyCountry = null;

    public function hasCompanyAddress(): bool
    {
        return null !== $this->companyStreet || null !== $this->companyZip || null !== $this->companyCity || null !== $this->companyCountry;
    }

    public function hasCompanyDetails(): bool
    {
        return null !== $this->company || null !== $this->vatId || $this->hasCompanyAddress();
    }
}
