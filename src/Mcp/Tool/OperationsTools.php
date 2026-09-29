<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\HousekeepingStatus;
use App\Entity\Reservation;
use App\Entity\RoomDayStatus;
use App\Entity\Subsidiary;
use App\Mcp\Security\McpDataFilter;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Mcp\Support\ReservationView;
use App\Service\HousekeepingViewService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Operations data behind the housekeeping, front desk and operations report pages, so assistants
 * can write their own daily plans and checklists.
 */
final class OperationsTools
{
    private const MAX_DAYS = 14;

    public function __construct(
        private readonly HousekeepingViewService $housekeepingViewService,
        private readonly ReservationView $reservationView,
        private readonly McpDataFilter $dataFilter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $occupancyTypes
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_operations_report',
        title: 'Operations report',
        description: 'Room plan per day for housekeeping and front desk (at most 14 days): for every room its occupancy (ARRIVAL, DEPARTURE, TURNOVER = departure and arrival on the same day, STAYOVER, BLOCKED, FREE), guest count and housekeeping status, plus the reservations involved with their booked extras such as breakfast. Guest and staff names, housekeeping notes and block reasons are only included when the access token may share guest data.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::OPERATIONS_READ)]
    public function report(
        #[Schema(description: 'First day, YYYY-MM-DD.')]
        string $start,
        #[Schema(type: 'string', description: 'Last day (inclusive), YYYY-MM-DD. Defaults to start.')]
        ?string $end = null,
        #[Schema(type: 'integer', description: 'Property (object) id; all properties when omitted.')]
        ?int $objectId = null,
        #[Schema(description: 'Only rooms with these occupancy types. Defaults to all.', items: ['type' => 'string', 'enum' => ['ARRIVAL', 'DEPARTURE', 'TURNOVER', 'STAYOVER', 'BLOCKED', 'FREE']])]
        array $occupancyTypes = [],
    ): array {
        $startDate = McpInput::date($start, 'start');
        $endDate = null !== $end ? McpInput::date($end, 'end') : $startDate;
        if ($endDate < $startDate) {
            throw McpToolException::invalid("'end' must not be before 'start'.");
        }
        if ((int) $startDate->diff($endDate)->days >= self::MAX_DAYS) {
            throw McpToolException::invalid(\sprintf('The range must not exceed %d days.', self::MAX_DAYS));
        }

        $subsidiary = null;
        if (null !== $objectId) {
            $subsidiary = $this->em->getRepository(Subsidiary::class)->find($objectId);
            if (!$subsidiary instanceof Subsidiary) {
                throw McpToolException::invalid('Unknown property (object) id.');
            }
        }

        $allowedTypes = $this->housekeepingViewService->getAllowedOccupancyTypes();
        $unknownTypes = array_diff($occupancyTypes, $allowedTypes);
        if ([] !== $unknownTypes) {
            throw McpToolException::invalid(\sprintf("Unknown 'occupancyTypes': %s. Allowed: %s.", implode(', ', $unknownTypes), implode(', ', $allowedTypes)));
        }

        $rangeView = $this->housekeepingViewService->buildRangeView(
            $startDate,
            $endDate,
            $subsidiary,
            [] !== $occupancyTypes ? array_values($occupancyTypes) : $allowedTypes,
        );

        $days = [];
        foreach ($rangeView['dayViews'] as $dateKey => $dayView) {
            $rooms = [];
            foreach ($dayView['rows'] as $row) {
                $rooms[] = $this->renderRoom($row);
            }
            $days[] = ['date' => $dateKey, 'rooms' => $rooms];
        }

        return [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d'),
            'objectId' => $objectId,
            'days' => $days,
            // Every reservation touching the range, also those of rooms filtered out above.
            'reservations' => array_map(
                fn (Reservation $reservation): array => $this->reservationView->render($reservation, withExtras: true),
                $rangeView['reservations'],
            ),
        ];
    }

    /**
     * @param array{apartment: Appartment, occupancyType: string, guestCount: int|null, reservationSummary: string|null, status: RoomDayStatus|null, apartmentReservations?: Reservation[]} $row
     *
     * @return array<string, mixed>
     */
    private function renderRoom(array $row): array
    {
        $status = $row['status'];
        $assignee = $status?->getAssignedTo();

        $room = [
            'apartmentId' => $row['apartment']->getId(),
            'number' => $row['apartment']->getNumber(),
            'occupancy' => $row['occupancyType'],
            'guests' => $row['guestCount'],
            'reservationIds' => array_map(
                static fn (Reservation $reservation): ?int => $reservation->getId(),
                $row['apartmentReservations'] ?? [],
            ),
            'housekeeping' => [
                // Rooms without a stored status count as open, as on the housekeeping page.
                'status' => ($status?->getHkStatus() ?? HousekeepingStatus::OPEN)->value,
                // Staff names are personal data as well and follow the guest data permission.
                'assignedTo' => null !== $assignee ? $this->dataFilter->personal(trim($assignee->getFirstname().' '.$assignee->getLastname())) : null,
                'note' => $this->dataFilter->untrustedText($status?->getNote()),
            ],
        ];

        // For blocked rooms the summary is the block reason; otherwise it holds guest names,
        // which the reservations list already carries through the data filter.
        if ('BLOCKED' === $row['occupancyType']) {
            $room['blockReason'] = $this->dataFilter->untrustedText($row['reservationSummary']);
        }

        return $room;
    }
}
