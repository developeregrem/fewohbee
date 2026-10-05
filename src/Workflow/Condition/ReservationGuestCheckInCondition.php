<?php

declare(strict_types=1);

namespace App\Workflow\Condition;

use App\Entity\Enum\GuestCheckInStatus;
use App\Entity\Reservation;
use App\Repository\GuestCheckInRepository;
use App\Service\GuestCheckIn\GuestCheckInConfigService;
use App\Service\GuestCheckIn\GuestCheckInLinkState;
use App\Service\GuestCheckIn\GuestCheckInPolicy;

/**
 * Online check-in state of the reservation, e.g. to invite or remind only guests who have not
 * checked in yet.
 *
 * Config:
 *   state string – open (the guest can still check in and has not done so)
 *                | submitted (the guest checked in, staff have not confirmed it yet)
 *                | confirmed (staff confirmed the check-in)
 *                | completed (the guest checked in, confirmed or not)
 */
class ReservationGuestCheckInCondition implements WorkflowConditionInterface
{
    public function __construct(
        private readonly GuestCheckInRepository $repository,
        private readonly GuestCheckInConfigService $configService,
        private readonly GuestCheckInPolicy $policy,
    ) {
    }

    public function getType(): string
    {
        return 'reservation.guest_checkin';
    }

    public function getLabelKey(): string
    {
        return 'workflow.condition.reservation_guest_checkin';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class];
    }

    /** @return list<array<string, mixed>> */
    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'state',
                'type' => 'select',
                'label' => 'workflow.condition.guest_checkin.label',
                'default' => 'open',
                'options' => [
                    ['value' => 'open', 'label' => 'workflow.condition.guest_checkin.open'],
                    ['value' => 'submitted', 'label' => 'workflow.condition.guest_checkin.submitted'],
                    ['value' => 'confirmed', 'label' => 'workflow.condition.guest_checkin.confirmed'],
                    ['value' => 'completed', 'label' => 'workflow.condition.guest_checkin.completed'],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public function evaluate(array $config, mixed $entity, array $context): bool
    {
        if (!$entity instanceof Reservation) {
            return false;
        }

        $checkIn = $this->repository->findOneByReservation($entity);
        $status = $checkIn?->getStatus() ?? GuestCheckInStatus::OPEN;

        return match ($config['state'] ?? 'open') {
            'submitted' => GuestCheckInStatus::SUBMITTED === $status,
            'confirmed' => GuestCheckInStatus::APPLIED === $status,
            'completed' => GuestCheckInStatus::OPEN !== $status,
            // "Open" only while an invitation still makes sense: feature on, link editable.
            default => GuestCheckInStatus::OPEN === $status
                && GuestCheckInLinkState::EDITABLE === $this->policy->linkState($entity, $checkIn, $this->configService->isEnabled()),
        };
    }
}
