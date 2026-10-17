<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\ReservationOrigin;
use App\Entity\ReservationStatus;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Mcp\Security\McpRequiresScope;
use App\Repository\GuestCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Master data the AI needs to call the other tools with valid ids.
 */
final class PropertyTools
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuestCategoryRepository $guestCategoryRepository,
    ) {
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    #[McpTool(
        name: 'get_property_overview',
        title: 'Property overview',
        description: 'Lists properties (objects), bookable rooms (apartments) with beds and category, room categories, guest categories, reservation statuses and booking origins with their ids. Call this first; the other tools expect these ids.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RESERVATIONS_READ)]
    public function overview(): array
    {
        $objects = array_map(
            static fn (Subsidiary $s): array => ['id' => $s->getId(), 'name' => $s->getName()],
            $this->em->getRepository(Subsidiary::class)->findBy([], ['name' => 'ASC'])
        );

        $rooms = [];
        foreach ($this->em->getRepository(Appartment::class)->findBy([], ['number' => 'ASC']) as $room) {
            $rooms[] = [
                'id' => $room->getId(),
                'number' => $room->getNumber(),
                'description' => $room->getDescription(),
                'beds' => $room->getBedsMax(),
                'objectId' => $room->getObject()?->getId(),
                'roomCategoryId' => $room->getRoomCategory()?->getId(),
                'sharedRoom' => $room->isMultipleOccupancy(),
                'active' => $room->isActive(),
            ];
        }

        $roomCategories = array_map(
            static fn (RoomCategory $c): array => ['id' => $c->getId(), 'name' => $c->getName()],
            $this->em->getRepository(RoomCategory::class)->findBy([], ['name' => 'ASC'])
        );

        $guestCategories = [];
        foreach ($this->guestCategoryRepository->findActiveOrdered() as $category) {
            $guestCategories[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'countedInOccupancy' => $category->isCountedInOccupancy(),
            ];
        }

        $statuses = array_map(
            static fn (ReservationStatus $s): array => ['id' => $s->getId(), 'name' => $s->getName(), 'blocksRoom' => $s->isBlocking()],
            $this->em->getRepository(ReservationStatus::class)->findBy([], ['name' => 'ASC'])
        );

        $origins = array_map(
            static fn (ReservationOrigin $o): array => ['id' => $o->getId(), 'name' => $o->getName()],
            $this->em->getRepository(ReservationOrigin::class)->findBy([], ['name' => 'ASC'])
        );

        return [
            'objects' => $objects,
            'apartments' => $rooms,
            'roomCategories' => $roomCategories,
            'guestCategories' => $guestCategories,
            'reservationStatuses' => $statuses,
            'origins' => $origins,
        ];
    }
}
