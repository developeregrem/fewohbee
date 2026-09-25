<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

use Symfony\Component\Validator\Constraints as Assert;

/** Everything a guest enters on the online check-in form. */
final class GuestCheckInSubmission
{
    /** Expected arrival as "HH:MM". */
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):[0-5]\d$/')]
    public ?string $arrivalTime = null;

    #[Assert\Valid]
    public GuestCheckInGuest $mainGuest;

    /** @var list<GuestCheckInCompanion> */
    #[Assert\Valid]
    public array $companions = [];

    #[Assert\Length(max: 1000)]
    public ?string $message = null;

    public function __construct()
    {
        $this->mainGuest = new GuestCheckInGuest();
    }
}
