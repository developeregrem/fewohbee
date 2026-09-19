<?php

declare(strict_types=1);

namespace App\Exception;

/** Reports why a remote ICS feed could not be downloaded safely. */
final class IcsFeedException extends CalendarSyncException
{
    public function __construct(
        public readonly IcsFeedFailure $failure,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        $translationKey = match ($failure) {
            IcsFeedFailure::HttpStatus => 'calendar.sync.error.http_status',
            IcsFeedFailure::Unreachable => 'calendar.sync.error.unreachable',
            IcsFeedFailure::TooLarge => 'calendar.sync.error.too_large',
        };

        parent::__construct(
            $translationKey,
            null !== $httpStatus ? ['%status%' => $httpStatus] : [],
            $previous,
        );
    }
}
