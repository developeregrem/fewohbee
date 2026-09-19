<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What a booking through a portal costs the house: the portal's commission and
 * its payment fee, each with its own base.
 *
 * @see \App\Service\OriginFeeCalculator
 */
final readonly class OriginFeeBreakdown
{
    /**
     * @param ?string $originName the portal the figures belong to, null when no
     *                            reservation on the invoice came through one -
     *                            in which case both fees are zero
     */
    public function __construct(
        public ?string $originName,
        public OriginFee $commission,
        public OriginFee $paymentFee,
    ) {
    }
}
