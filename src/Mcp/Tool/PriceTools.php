<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Api\PriceDto;
use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Price;
use App\Entity\PriceRule;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Mcp\Support\StayInput;
use App\Repository\PriceRepository;
use App\Repository\PriceRuleRepository;
use App\Repository\SubsidiaryRepository;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\PriceQuoteService;
use App\Service\Api\RateCalendarService;
use App\Service\Api\StayParameterResolver;
use App\Service\AppSettingsService;
use App\Service\Pricing\PriceCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Price information: quotes calculated by the same pipeline as invoices, the rate calendar, the
 * configured price rows and the price rules that change them.
 */
final class PriceTools
{
    private const MAX_RATE_NIGHTS = 366;
    private const MAX_PRICE_ROWS = 100;

    public function __construct(
        private readonly PriceQuoteService $priceQuoteService,
        private readonly StayParameterResolver $stayParameterResolver,
        private readonly RateCalendarService $rateCalendarService,
        private readonly PriceRepository $priceRepository,
        private readonly PriceRuleRepository $priceRuleRepository,
        private readonly SubsidiaryRepository $subsidiaryRepository,
        private readonly PriceCalendarService $priceCalendarService,
        private readonly AppSettingsService $settings,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param array<string, int> $guestCounts
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_price_quote',
        title: 'Price quote',
        description: 'Calculates the price of a stay in one room, per night and in total, with the same rules as invoices. Also lists the extras (breakfast, dog, ...) that can be booked for the stay with their price; their ids go into preview_reservation. Tourist tax is included when the access token may read it. Does not check availability.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_READ)]
    public function quote(
        #[Schema(description: 'Room (apartment) id.', minimum: 1)]
        int $apartmentId,
        #[Schema(description: 'Arrival date, YYYY-MM-DD.')]
        string $arrival,
        #[Schema(description: 'Departure date, YYYY-MM-DD.')]
        string $departure,
        #[Schema(type: 'integer', description: 'Occupancy the room price is based on. Derived from guestCounts when omitted.', minimum: 1)]
        ?int $persons = null,
        #[Schema(description: 'Guests per guest category id, e.g. {"1": 2, "3": 1}. Needed for child discounts and tourist tax.', type: 'object', additionalProperties: ['type' => 'integer', 'minimum' => 0])]
        array $guestCounts = [],
        #[Schema(type: 'integer', description: 'Booking origin id; prices can differ per origin. Defaults to the online booking origin.')]
        ?int $originId = null,
    ): array {
        $stay = StayInput::resolve($this->em, $this->stayParameterResolver, $apartmentId, $arrival, $departure, $persons, $guestCounts, $originId);

        $quote = $this->priceQuoteService->quote(
            $stay->apartment,
            $stay->arrival,
            $stay->departure,
            $stay->persons,
            $stay->guestCounts,
            $stay->origin,
            $this->authorizationChecker->isGranted(ApiScopeVoter::TOURIST_TAX_READ),
        );

        /** @var array<string, mixed> $data */
        $data = json_decode((string) json_encode($quote), true);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_rate_calendar',
        title: 'Rate calendar',
        description: 'Which room price applies per night and occupancy for a room category (at most 366 nights): the price list (baseUnitPrice), changed by price rules (priceRules, adjustmentPercent) or replaced by a day price (dayPrice), held within the limits (limited). Price rules and day prices can differ per branch, so pass objectId or apartmentId. Consecutive nights with identical rates are merged into one period; a period without rates cannot be priced. The rate depends on the intended stay length because of minimum stay rules, so pass nights for longer stays. For the total of a concrete stay use get_price_quote.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_READ)]
    public function rateCalendar(
        #[Schema(description: 'First night, YYYY-MM-DD.')]
        string $start,
        #[Schema(type: 'string', description: 'Last night (inclusive), YYYY-MM-DD. Defaults to start.')]
        ?string $end = null,
        #[Schema(type: 'integer', description: 'Room category id. Required unless apartmentId is given.')]
        ?int $roomCategoryId = null,
        #[Schema(type: 'integer', description: 'Room (apartment) id; its room category and branch are used.')]
        ?int $apartmentId = null,
        #[Schema(type: 'integer', description: 'Branch (subsidiary) id. Defaults to the branch of apartmentId, otherwise to a branch with a room of the category.')]
        ?int $objectId = null,
        #[Schema(description: 'Intended stay length in nights; selects rates with a minimum stay.', minimum: 1, maximum: 366)]
        int $nights = 1,
        #[Schema(type: 'integer', description: 'Only this occupancy (number of persons). Defaults to every occupancy with a price.', minimum: 1)]
        ?int $occupancy = null,
        #[Schema(type: 'integer', description: 'Booking origin id; prices can differ per origin. Defaults to the online booking origin.')]
        ?int $originId = null,
    ): array {
        $firstNight = McpInput::date($start, 'start');
        $lastNight = null !== $end ? McpInput::date($end, 'end') : $firstNight;
        if ($lastNight < $firstNight) {
            throw McpToolException::invalid("'end' must not be before 'start'.");
        }
        if ((int) $firstNight->diff($lastNight)->days >= self::MAX_RATE_NIGHTS) {
            throw McpToolException::invalid(\sprintf('The range must not exceed %d nights.', self::MAX_RATE_NIGHTS));
        }
        if ($nights < 1 || $nights > self::MAX_RATE_NIGHTS) {
            throw McpToolException::invalid(\sprintf("'nights' must be between 1 and %d.", self::MAX_RATE_NIGHTS));
        }
        if (null !== $occupancy && $occupancy < 1) {
            throw McpToolException::invalid("'occupancy' must be at least 1.");
        }

        [$roomCategory, $sampleRoom] = $this->resolveRoomCategory($roomCategoryId, $apartmentId, $objectId);
        $origin = McpInput::guard(fn (): ReservationOrigin => $this->stayParameterResolver->resolveOrigin($originId));
        // numberOfPersons is matched exactly, so only occupancies with a price are worth asking for.
        $occupancies = null !== $occupancy ? [$occupancy] : $this->priceRepository->findOccupanciesForRoomCategory($roomCategory);

        $calendar = $this->rateCalendarService->build($sampleRoom, $firstNight, $lastNight, $nights, $occupancies, $origin);

        return [
            'roomCategory' => ['id' => $roomCategory->getId(), 'name' => $roomCategory->getName()],
            'branch' => ['id' => $sampleRoom->getObject()?->getId(), 'name' => $sampleRoom->getObject()?->getName()],
            'origin' => ['id' => $origin->getId(), 'name' => $origin->getName()],
            'nights' => $nights,
            'occupancies' => $occupancies,
            'periods' => self::mergeNights($calendar),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_price_list',
        title: 'Price list',
        description: 'The configured price rows (the price list): room prices and extras with amount, occupancy, minimum stay, weekdays, season, special periods, booking origins and room categories. Price rules and day prices change the room prices on top (see get_price_rules, get_rate_calendar). Use it to explain when which price applies; for the total of a concrete stay use get_price_quote. At most 100 rows per call.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_READ)]
    public function priceList(
        #[Schema(type: 'string', description: 'Only room prices or only extras.', enum: ['apartment', 'misc'])]
        ?string $type = null,
        #[Schema(type: 'integer', description: 'Only rows for this room category.')]
        ?int $roomCategoryId = null,
        #[Schema(type: 'integer', description: 'Only rows for this booking origin.')]
        ?int $originId = null,
        #[Schema(description: 'Also return inactive rows.')]
        bool $includeInactive = false,
        #[Schema(description: 'Number of rows to skip (pagination).', minimum: 0)]
        int $offset = 0,
    ): array {
        $typeId = match ($type) {
            null => null,
            'apartment' => 2,
            'misc' => 1,
            default => throw McpToolException::invalid("'type' must be 'apartment' or 'misc'."),
        };
        if (null !== $roomCategoryId && !$this->em->getRepository(RoomCategory::class)->find($roomCategoryId) instanceof RoomCategory) {
            throw McpToolException::invalid('Unknown room category id.');
        }
        if (null !== $originId && !$this->em->getRepository(ReservationOrigin::class)->find($originId) instanceof ReservationOrigin) {
            throw McpToolException::invalid('Unknown origin id.');
        }

        $prices = $this->priceRepository->findForCatalogue(
            $typeId,
            $roomCategoryId,
            null !== $originId ? [$originId] : [],
            $includeInactive ? null : true,
        );
        $offset = max(0, $offset);
        // Sliced here: the catalogue query fetch-joins collections, which SQL limits would cut.
        $page = \array_slice($prices, $offset, self::MAX_PRICE_ROWS);

        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) json_encode(array_map(static fn (Price $price): PriceDto => PriceDto::fromEntity($price), $page)), true);

        return [
            'prices' => $rows,
            'total' => \count($prices),
            'offset' => $offset,
            'hasMore' => $offset + \count($page) < \count($prices),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_price_rules',
        title: 'Price rules',
        description: 'The price rules that change room prices by a percentage, and the limits they are held within. Conditions: always (every night of the weekdays and the period), last_minute (night at most days ahead), early_bird (night at least days ahead), occupancy_high (at least occupancyPercent of the rooms booked), occupancy_low (at most occupancyPercent booked and night at most days ahead). The percentages of all rules that apply on a night add up; a day price takes their place. Rules apply to new bookings; existing bookings keep their price. Flat prices and extras never change.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_READ)]
    public function priceRules(): array
    {
        $settings = $this->settings->getSettings();

        return [
            'rules' => array_map(self::describeRule(...), $this->priceRuleRepository->findForSettings()),
            'limits' => [
                'minPercent' => $settings->getPriceChangeMinPercent(),
                'maxPercent' => $settings->getPriceChangeMaxPercent(),
                'rounding' => $settings->getPriceChangeRounding()->value,
            ],
            'note' => 'Prices changed by rules are held between minPercent and maxPercent of the price list and rounded; day prices set by hand are not held within the limits.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function describeRule(PriceRule $rule): array
    {
        $condition = $rule->getCondition();

        return [
            'id' => $rule->getId(),
            'name' => $rule->getName(),
            'enabled' => $rule->isEnabled(),
            'condition' => $condition->value,
            'percent' => $rule->getPercent(),
            'days' => $condition->usesDays() ? $rule->getDays() : null,
            'occupancyPercent' => $condition->usesOccupancy() ? $rule->getOccupancy() : null,
            'occupancyAcrossBranches' => $condition->usesOccupancy() ? $rule->isOccupancyAcrossSubsidiaries() : null,
            'weekdays' => $rule->getWeekdays(),
            'firstNight' => $rule->getStartDate()?->format('Y-m-d'),
            'lastNight' => $rule->getEndDate()?->modify('-1 day')->format('Y-m-d'),
            'branches' => $rule->isAllSubsidiaries() ? 'all' : array_map(
                static fn (Subsidiary $subsidiary): array => ['id' => $subsidiary->getId(), 'name' => $subsidiary->getName()],
                $rule->getSubsidiaries()->getValues(),
            ),
            'roomCategories' => $rule->isAllCategories() ? 'all' : array_map(
                static fn (RoomCategory $category): array => ['id' => $category->getId(), 'name' => $category->getName()],
                $rule->getCategories()->getValues(),
            ),
        ];
    }

    /**
     * The room category to report and a room of it to price with (the rate calendar prices a
     * sample reservation; price rules and day prices depend on the room's branch).
     *
     * @return array{0: RoomCategory, 1: Appartment}
     */
    private function resolveRoomCategory(?int $roomCategoryId, ?int $apartmentId, ?int $objectId = null): array
    {
        $subsidiary = null;
        if (null !== $objectId) {
            $subsidiary = $this->subsidiaryRepository->find($objectId);
            if (!$subsidiary instanceof Subsidiary) {
                throw McpToolException::invalid('Unknown branch (objectId).');
            }
        }

        if (null !== $apartmentId) {
            $apartment = $this->em->getRepository(Appartment::class)->find($apartmentId);
            if (!$apartment instanceof Appartment) {
                throw McpToolException::invalid('Unknown room (apartment) id.');
            }
            $category = $apartment->getRoomCategory();
            if (!$category instanceof RoomCategory) {
                throw McpToolException::invalid('This room has no room category and therefore no room prices.');
            }
            if (null !== $subsidiary && $apartment->getObject()?->getId() !== $subsidiary->getId()) {
                throw McpToolException::invalid('This room belongs to another branch than objectId.');
            }

            return [$category, $apartment];
        }

        if (null === $roomCategoryId) {
            throw McpToolException::invalid("Pass 'roomCategoryId' or 'apartmentId'.");
        }
        $category = $this->em->getRepository(RoomCategory::class)->find($roomCategoryId);
        if (!$category instanceof RoomCategory) {
            throw McpToolException::invalid('Unknown room category id.');
        }
        if (null !== $subsidiary) {
            $sampleRoom = $this->priceCalendarService->sampleRoom($subsidiary, $category)
                ?? throw McpToolException::invalid('This branch has no room of this room category.');

            return [$category, $sampleRoom];
        }
        $sampleRoom = $this->em->getRepository(Appartment::class)->findOneBy(['roomCategory' => $category]);
        if (!$sampleRoom instanceof Appartment) {
            throw McpToolException::invalid('No room is assigned to this room category.');
        }

        return [$category, $sampleRoom];
    }

    /**
     * Merges consecutive nights with identical rates into periods, which keeps a year of rates
     * small enough for the model.
     *
     * @param list<array{date: string, rates: list<array<string, mixed>>}> $calendar
     *
     * @return list<array{firstNight: string, lastNight: string, rates: list<array<string, mixed>>}>
     */
    public static function mergeNights(array $calendar): array
    {
        $periods = [];
        foreach ($calendar as $night) {
            $last = array_key_last($periods);
            if (null !== $last && $periods[$last]['rates'] === $night['rates']) {
                $periods[$last]['lastNight'] = $night['date'];
                continue;
            }
            $periods[] = ['firstNight' => $night['date'], 'lastNight' => $night['date'], 'rates' => $night['rates']];
        }

        return $periods;
    }
}
