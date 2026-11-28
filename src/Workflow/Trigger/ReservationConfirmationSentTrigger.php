<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\MailCorrespondence;
use App\Entity\Reservation;
use App\Service\AppSettingsService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires once per booking when its confirmation (the mail template from the general settings)
 * went out, e.g. to set the reservations to "confirmed". Only offered while a confirmation
 * template is set, as the button is.
 */
class ReservationConfirmationSentTrigger implements ConditionalWorkflowTriggerInterface
{
    public const TYPE = 'reservation.confirmation_sent';

    public function __construct(
        private readonly AppSettingsService $appSettings,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.reservation_confirmation_sent';
    }

    public function getGroup(): WorkflowTriggerGroup
    {
        return WorkflowTriggerGroup::BOOKINGS;
    }

    public function getEntityClass(): ?string
    {
        return Reservation::class;
    }

    /** @return array{} */
    public function getConfigSchema(): array
    {
        return [];
    }

    public function isEventDriven(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return null !== $this->appSettings->getSettings()->getConfirmationTemplate();
    }

    /**
     * Reservations that got the confirmation, newest first.
     *
     * @param array<string, mixed> $config
     *
     * @return list<Reservation>
     */
    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        $template = $this->appSettings->getSettings()->getConfirmationTemplate();
        if (null === $template) {
            return [];
        }

        /** @var list<Reservation> $reservations */
        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('DISTINCT r')
            ->innerJoin(MailCorrespondence::class, 'm', 'WITH', 'm.reservation = r')
            ->where('m.template IN (:templates)')
            ->setParameter('templates', [$template, ...$template->getVariants()->toArray()])
            ->orderBy('r.id', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $reservations;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return int[]
     */
    public function findMatchingIds(EntityManagerInterface $em, array $config, int $limit = 500): array
    {
        return [];
    }
}
