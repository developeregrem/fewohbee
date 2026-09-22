<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Reservation;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Mcp\Support\ReservationView;
use App\Repository\InvoiceRepository;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\InvoiceDtoBuilder;
use App\Service\Api\ReservationQueryService;
use App\Service\AvailabilityService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Read access to reservations and room availability.
 */
final class ReservationTools
{
    private const MAX_RESULTS = 200;
    private const MAX_AVAILABILITY_NIGHTS = 366;

    public function __construct(
        private readonly ReservationQueryService $reservationQueryService,
        private readonly ReservationView $reservationView,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly AvailabilityService $availabilityService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<int> $statusIds
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'search_reservations',
        title: 'Search reservations',
        description: 'Finds reservations that touch a date range (at most 180 days), optionally filtered by property, room, status or type. Guest names and remarks are only included when the access token may share guest data.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RESERVATIONS_READ)]
    public function search(
        #[Schema(description: 'First day of the range, YYYY-MM-DD.')]
        string $start,
        #[Schema(type: 'string', description: 'Last day of the range (inclusive), YYYY-MM-DD. Defaults to start.')]
        ?string $end = null,
        #[Schema(type: 'integer', description: 'Property (object) id.')]
        ?int $objectId = null,
        #[Schema(type: 'integer', description: 'Room (apartment) id.')]
        ?int $apartmentId = null,
        #[Schema(description: 'Reservation status ids. Defaults to all statuses that block a room.', items: ['type' => 'integer'])]
        array $statusIds = [],
        #[Schema(type: 'string', description: 'Only reservations arriving, departing or staying (in house) within the range.', enum: ['arrival', 'departure', 'inhouse'])]
        ?string $type = null,
        #[Schema(description: 'Number of reservations to skip (pagination).', minimum: 0)]
        int $offset = 0,
    ): array {
        $startDate = McpInput::date($start, 'start');
        $endDate = null !== $end ? McpInput::date($end, 'end') : $startDate;

        $matched = McpInput::guard(fn (): array => $this->reservationQueryService->find(
            $startDate,
            $endDate,
            null !== $objectId ? (string) $objectId : null,
            $apartmentId,
            $statusIds,
            $type,
        ));

        $total = \count($matched);
        $page = \array_slice($matched, max(0, $offset), self::MAX_RESULTS);
        $invoices = $this->loadInvoiceSummaries(array_map(static fn (array $row): int => (int) $row[0]->getId(), $page));

        $reservations = [];
        foreach ($page as [$reservation, $types]) {
            $reservations[] = $this->reservationView->render(
                $reservation,
                $types,
                null === $invoices ? null : InvoiceDtoBuilder::mapSummaries($invoices[(int) $reservation->getId()] ?? []),
            );
        }

        return [
            'reservations' => $reservations,
            'total' => $total,
            'offset' => max(0, $offset),
            'hasMore' => $offset + \count($page) < $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_reservation',
        title: 'Get reservation',
        description: 'Returns one reservation by id with its booked extras, and linked invoices when the access token may read invoices.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RESERVATIONS_READ)]
    public function get(
        #[Schema(description: 'Reservation id.', minimum: 1)]
        int $reservationId,
    ): array {
        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        if (!$reservation instanceof Reservation) {
            throw McpToolException::invalid('Unknown reservation id.');
        }

        $invoices = $this->loadInvoiceSummaries([$reservationId]);

        return $this->reservationView->render(
            $reservation,
            [],
            null === $invoices ? null : InvoiceDtoBuilder::mapSummaries($invoices[$reservationId] ?? []),
            withExtras: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'check_availability',
        title: 'Check availability',
        description: 'Lists the active rooms that are free for the whole stay and have enough beds. The departure day is not a night.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RESERVATIONS_READ)]
    public function availability(
        #[Schema(description: 'Arrival date, YYYY-MM-DD.')]
        string $arrival,
        #[Schema(description: 'Departure date, YYYY-MM-DD.')]
        string $departure,
        #[Schema(description: 'Number of guests who need a bed.', minimum: 1)]
        int $persons = 1,
        #[Schema(type: 'integer', description: 'Restrict to one property (object) id.')]
        ?int $objectId = null,
        #[Schema(type: 'integer', description: 'Restrict to one room category id.')]
        ?int $roomCategoryId = null,
    ): array {
        $start = McpInput::date($arrival, 'arrival');
        $end = McpInput::date($departure, 'departure');
        if ($end <= $start) {
            throw McpToolException::invalid("'departure' must be after 'arrival'.");
        }
        if ((int) $start->diff($end)->days > self::MAX_AVAILABILITY_NIGHTS) {
            throw McpToolException::invalid(\sprintf('A stay must not exceed %d nights.', self::MAX_AVAILABILITY_NIGHTS));
        }
        if ($persons < 1) {
            throw McpToolException::invalid("'persons' must be at least 1.");
        }

        $criteria = ['active' => true];
        if (null !== $objectId) {
            $criteria['object'] = $objectId;
        }
        if (null !== $roomCategoryId) {
            $criteria['roomCategory'] = $roomCategoryId;
        }

        $available = [];
        foreach ($this->em->getRepository(Appartment::class)->findBy($criteria, ['number' => 'ASC']) as $room) {
            if ($this->availabilityService->isRoomAvailable($room, $start, $end, $persons)) {
                $available[] = [
                    'id' => $room->getId(),
                    'number' => $room->getNumber(),
                    'description' => $room->getDescription(),
                    'beds' => $room->getBedsMax(),
                    'objectId' => $room->getObject()?->getId(),
                    'roomCategoryId' => $room->getRoomCategory()?->getId(),
                ];
            }
        }

        return [
            'arrival' => $start->format('Y-m-d'),
            'departure' => $end->format('Y-m-d'),
            'nights' => (int) $start->diff($end)->days,
            'persons' => $persons,
            'availableApartments' => $available,
        ];
    }

    /**
     * Linked invoices are only disclosed to tokens that may read invoices; null tells the
     * caller the scope is missing.
     *
     * @param int[] $reservationIds
     *
     * @return array<int, list<array{id: int, number: string, date: \DateTimeInterface, status: int}>>|null
     */
    private function loadInvoiceSummaries(array $reservationIds): ?array
    {
        if (!$this->authorizationChecker->isGranted(ApiScopeVoter::INVOICES_READ)) {
            return null;
        }

        return $this->invoiceRepository->findSummariesByReservationIds($reservationIds);
    }
}
