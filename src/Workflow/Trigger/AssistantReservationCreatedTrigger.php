<?php

declare(strict_types=1);

namespace App\Workflow\Trigger;

use App\Entity\Reservation;
use App\Service\Mcp\McpSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fires when an AI assistant created a reservation through the MCP server. Only offered while
 * MCP is switched on.
 */
class AssistantReservationCreatedTrigger implements ConditionalWorkflowTriggerInterface
{
    public const TYPE = 'assistant_booking.created';

    public function __construct(
        private readonly McpSettings $mcpSettings,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getLabelKey(): string
    {
        return 'workflow.trigger.assistant_booking_created';
    }

    public function getEntityClass(): ?string
    {
        return Reservation::class;
    }

    /** @return array<string, mixed> */
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
        return $this->mcpSettings->isActive();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<Reservation>
     */
    public function findPreviewEntities(EntityManagerInterface $em, array $config, int $limit = 20): array
    {
        // Assistant bookings carry no marker on the reservation itself; recent reservations
        // are a sufficient preview of what the trigger works on.
        /** @var list<Reservation> $reservations */
        $reservations = $em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->orderBy('r.reservationDate', 'DESC')
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
