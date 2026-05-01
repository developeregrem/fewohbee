<?php

declare(strict_types=1);

namespace App\Workflow\Action;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationStatus;
use App\Service\ReservationService;
use App\Workflow\WorkflowSkippedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Changes the reservation status of one or more reservations.
 *
 * When triggered on a Reservation entity, changes that single reservation's status.
 * When triggered on an Invoice entity, changes the status of all linked reservations.
 */
class ChangeReservationStatusAction implements WorkflowActionInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ReservationService $reservationService,
    ) {
    }

    public function getType(): string
    {
        return 'change_reservation_status';
    }

    public function getLabelKey(): string
    {
        return 'workflow.action.change_reservation_status';
    }

    public function getSupportedEntityClasses(): array
    {
        return [Reservation::class, Invoice::class];
    }

    public function getSupportedTriggerTypes(): array
    {
        return [];
    }

    public function getConfigSchema(): array
    {
        return [
            [
                'key' => 'statusId',
                'type' => 'reservation_status_select',
                'label' => 'workflow.action.change_reservation_status',
            ],
        ];
    }

    public function execute(array $config, mixed $entity, array $context): string
    {
        $statusId = (int) ($config['statusId'] ?? 0);
        $status = $this->em->find(ReservationStatus::class, $statusId);

        if (null === $status) {
            throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_status_not_found'));
        }

        if ($entity instanceof Reservation) {
            return $this->changeStatus([$entity], $status);
        }

        if ($entity instanceof Invoice) {
            $reservations = $entity->getReservations()->toArray();

            if (0 === count($reservations)) {
                throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_no_reservations'));
            }

            return $this->changeStatus($reservations, $status);
        }

        throw new WorkflowSkippedException($this->translator->trans('workflow.log.skipped_unsupported_entity'));
    }

    /**
     * @param Reservation[] $reservations
     */
    private function changeStatus(array $reservations, ReservationStatus $status): string
    {
        foreach ($reservations as $reservation) {
            $this->reservationService->changeStatus($reservation, $status, flush: false);
        }

        $this->em->flush();

        return $this->translator->trans('workflow.log.reservation_status_changed', [
            '%count%' => count($reservations),
            '%status%' => $status->getName(),
        ]);
    }
}
