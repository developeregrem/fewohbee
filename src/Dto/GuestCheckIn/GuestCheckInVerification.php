<?php

declare(strict_types=1);

namespace App\Dto\GuestCheckIn;

use Symfony\Component\Validator\Constraints as Assert;

/** Booking details a visitor confirms before the check-in page shows anything. */
final class GuestCheckInVerification
{
    #[Assert\NotNull]
    public ?\DateTimeImmutable $arrival = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $departure = null;

    #[Assert\Length(max: 100)]
    public ?string $lastname = null;
}
