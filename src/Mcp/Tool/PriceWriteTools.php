<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Pricing\SpecialPricePlan;
use App\Dto\Pricing\SpecialPriceRequest;
use App\Entity\ApiToken;
use App\Entity\Appartment;
use App\Entity\Enum\ApiScope;
use App\Entity\Price;
use App\Entity\ReservationOrigin;
use App\Entity\RoomCategory;
use App\Exception\SpecialPriceException;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\McpToolException;
use App\Mcp\Security\PreviewTokenSigner;
use App\Mcp\Support\McpInput;
use App\Security\ApiTokenContext;
use App\Service\Api\RateCalendarService;
use App\Service\Pricing\SpecialPriceService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Special-period prices (trade fairs, festivals, ...) proposed by an assistant and saved only after
 * the user confirmed the preview. Changing prices is otherwise a settings task; this exception
 * with preview and prices:write, which only administrators can grant, is a decision recorded in
 * docs/mcp-roadmap.md.
 */
final class PriceWriteTools
{
    private const IDEMPOTENCY_TTL = 86400;
    private const MAX_LISTED_RESERVATIONS = 50;

    public function __construct(
        private readonly SpecialPriceService $specialPriceService,
        private readonly RateCalendarService $rateCalendarService,
        private readonly PreviewTokenSigner $previewTokenSigner,
        private readonly ApiTokenContext $apiTokenContext,
        private readonly McpToolAuditor $auditor,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.mcp_write')]
        private readonly RateLimiterFactoryInterface $writeLimiter,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'preview_special_price',
        title: 'Preview special price',
        description: 'Checks a special-period price without saving it. Either adds the period to an existing room price row that already has special periods (omit amount), or creates a new row as a copy of a room price row with a new amount (pass amount); the copy keeps occupancy and minimum stay of the source row. Priority per night: special rows before year-round rows; among them the row with the higher minimum stay wins once the stay is long enough, the others keep the shorter stays. So only special rows with the same occupancy and minimum stay conflict; rows with another minimum stay are listed under rowsForOtherStayLengths and need no cutting. Returns the current rates, conflicts, those rows, and reservations without invoice whose price would change. Without conflicts, or with overwriteConflicts, it returns a previewToken valid for 10 minutes.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_WRITE)]
    public function preview(
        #[Schema(description: 'Room price row id (see get_price_rules, type "apartment").', minimum: 1)]
        int $priceId,
        #[Schema(description: 'First night of the special period, YYYY-MM-DD.')]
        string $firstNight,
        #[Schema(description: 'Last night of the special period (inclusive), YYYY-MM-DD.')]
        string $lastNight,
        #[Schema(description: 'Label of the period, e.g. the event name. Shown in the FewohBee price list.', minLength: 3, maxLength: 100)]
        string $periodDescription,
        #[Schema(type: 'number', description: 'New amount for a copy of the price row, in the same unit as the row (per room or per person and night, or flat). Omit to add the period to the row itself.', minimum: 0.01)]
        ?float $amount = null,
        #[Schema(type: 'string', description: 'Description of the new row. Defaults to the source description plus the period label.', minLength: 3, maxLength: 100)]
        ?string $rowDescription = null,
        #[Schema(description: 'Cut these nights out of the conflicting special rows. Only after the user saw the conflicts and agreed.')]
        bool $overwriteConflicts = false,
    ): array {
        $plan = $this->plan($priceId, $firstNight, $lastNight, $periodDescription, $amount, $rowDescription, $overwriteConflicts);

        $result = $this->renderPlan($plan);
        if (!$plan->canApply()) {
            return $result + [
                'nextStep' => 'Other special price rows apply on these nights (see conflicts). Show them to the user. Only if the user wants the new price to replace them on these nights, call preview_special_price again with overwriteConflicts: true.',
            ];
        }

        $previewToken = $this->previewTokenSigner->sign($this->currentApiToken()->getId() ?? 0, $plan->fingerprint());

        return $result + [
            'previewToken' => $previewToken,
            'previewTokenExpiresAt' => PreviewTokenSigner::expiresAt($previewToken)?->format(\DateTimeInterface::ATOM),
            'nextStep' => 'Show the new price, the current rates, the conflicts that will be overwritten and the bookings that keep their booked price to the user. Only after the user agreed, call create_special_price with exactly the same arguments plus previewToken.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_special_price',
        title: 'Create special price',
        description: 'Saves the special-period price exactly as previewed. Requires the previewToken from preview_special_price and the same arguments; fails when the price list changed since the preview. Calling it again with the same previewToken returns the already saved result.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_WRITE)]
    public function create(
        #[Schema(description: 'The previewToken returned by preview_special_price.', minLength: 10, maxLength: 200)]
        string $previewToken,
        #[Schema(description: 'Same value as in preview_special_price.', minimum: 1)]
        int $priceId,
        #[Schema(description: 'Same value as in preview_special_price.')]
        string $firstNight,
        #[Schema(description: 'Same value as in preview_special_price.')]
        string $lastNight,
        #[Schema(description: 'Same value as in preview_special_price.', minLength: 3, maxLength: 100)]
        string $periodDescription,
        #[Schema(type: 'number', description: 'Same value as in preview_special_price.', minimum: 0.01)]
        ?float $amount = null,
        #[Schema(type: 'string', description: 'Same value as in preview_special_price.', minLength: 3, maxLength: 100)]
        ?string $rowDescription = null,
        #[Schema(description: 'Same value as in preview_special_price.')]
        bool $overwriteConflicts = false,
    ): array {
        $apiToken = $this->currentApiToken();

        // A retried call (e.g. after a dropped connection) returns what it already saved. Checked
        // first: after saving, the same request no longer plans (the period exists now).
        $cacheItem = $this->cache->getItem('mcp_special_price_'.hash('sha256', $apiToken->getId().'|'.$previewToken));
        if ($cacheItem->isHit()) {
            $this->auditor->note('replay', true);

            return ['created' => false, 'alreadyCreated' => true] + (array) $cacheItem->get();
        }

        $plan = $this->plan($priceId, $firstNight, $lastNight, $periodDescription, $amount, $rowDescription, $overwriteConflicts);
        if (!$this->previewTokenSigner->verify($previewToken, $apiToken->getId() ?? 0, $plan->fingerprint())) {
            throw McpToolException::invalid('The previewToken is missing, expired or does not match these arguments or the current price list. Call preview_special_price again and confirm the result with the user.');
        }
        if (!$this->writeLimiter->create('prices-token-'.$apiToken->getId())->consume()->isAccepted()) {
            throw McpToolException::rateLimited('Too many price changes were made with this access token within the last hour. Try again later.');
        }

        try {
            $target = $this->specialPriceService->apply($plan);
        } catch (SpecialPriceException $e) {
            throw McpToolException::invalid($e->getMessage());
        }

        $this->auditor->note('priceId', (int) $target->getId());
        $this->auditor->note('sourcePriceId', (int) $plan->source->getId());
        $this->auditor->note('firstNight', $plan->request->firstNight->format('Y-m-d'));
        $this->auditor->note('lastNight', $plan->request->lastNight->format('Y-m-d'));
        $this->auditor->note('overwrittenRows', array_map(static fn (array $conflict): int => (int) $conflict['price']->getId(), $plan->conflicts));

        $result = [
            'price' => $this->describeRow($target),
            'period' => $this->describePeriod($plan),
            'rates' => $this->rates($target, $plan->request->firstNight, $plan->request->lastNight),
        ];
        $cacheItem->set($result)->expiresAfter(self::IDEMPOTENCY_TTL);
        $this->cache->save($cacheItem);

        return ['created' => true, 'alreadyCreated' => false] + $result;
    }

    private function plan(int $priceId, string $firstNight, string $lastNight, string $periodDescription, ?float $amount, ?string $rowDescription, bool $overwriteConflicts): SpecialPricePlan
    {
        $request = new SpecialPriceRequest(
            $priceId,
            McpInput::date($firstNight, 'firstNight'),
            McpInput::date($lastNight, 'lastNight'),
            trim($periodDescription),
            $amount,
            null !== $rowDescription ? trim($rowDescription) : null,
            $overwriteConflicts,
        );

        try {
            return $this->specialPriceService->plan($request);
        } catch (SpecialPriceException $e) {
            throw McpToolException::invalid($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function renderPlan(SpecialPricePlan $plan): array
    {
        $request = $plan->request;
        $sourceCategories = $this->ids($plan->source->getRoomCategories()->toArray());
        $sourceOrigins = $this->ids($plan->source->getReservationOrigins()->toArray());

        $conflicts = [];
        foreach ($plan->conflicts as $conflict) {
            $price = $conflict['price'];
            $periods = [];
            foreach ($conflict['periods'] as $entry) {
                [$start, $end] = SpecialPriceService::dates($entry['period']);
                $periods[] = [
                    'firstNight' => $start->format('Y-m-d'),
                    'lastNight' => $end->format('Y-m-d'),
                    'description' => $entry['period']->getDescription(),
                    'whenOverwritten' => match (\count($entry['remaining'])) {
                        0 => 'removed',
                        1 => 'shortened',
                        default => 'split',
                    },
                    'remaining' => array_map(static fn (array $range): array => ['firstNight' => $range[0]->format('Y-m-d'), 'lastNight' => $range[1]->format('Y-m-d')], $entry['remaining']),
                ];
            }
            $conflicts[] = $this->describeRow($price) + [
                'periods' => $periods,
                // Overwriting also takes the row's price away from these on the new nights.
                'alsoAppliesTo' => [
                    'roomCategories' => $this->names($price->getRoomCategories()->filter(static fn (RoomCategory $category): bool => !\in_array($category->getId(), $sourceCategories, true))->toArray()),
                    'origins' => $this->names($price->getReservationOrigins()->filter(static fn (ReservationOrigin $origin): bool => !\in_array($origin->getId(), $sourceOrigins, true))->toArray()),
                ],
            ];
        }

        return [
            'action' => $request->createsRow() ? 'create_price_row' : 'add_period_to_row',
            'source' => $this->describeRow($plan->source),
            'newRow' => $request->createsRow() ? [
                'description' => $this->specialPriceService->rowDescription($plan),
                'amount' => round((float) $request->amount, 2),
            ] : null,
            'period' => $this->describePeriod($plan),
            'currentRates' => $this->rates($plan->source, $request->firstNight, $request->lastNight),
            'conflicts' => $conflicts,
            'rowsForOtherStayLengths' => array_map(fn (Price $price): array => $this->describeRow($price) + [
                'stays' => $this->stayLengthSplit($plan->source, $price),
            ], $plan->otherStayLengths),
            'affectedReservations' => [
                'total' => \count($plan->affectedReservations),
                'reservations' => array_map(static fn (array $row): array => [
                    'id' => $row['id'],
                    'arrival' => $row['startDate'],
                    'departure' => $row['endDate'],
                    'apartment' => $row['apartmentNumber'],
                ], \array_slice($plan->affectedReservations, 0, self::MAX_LISTED_RESERVATIONS)),
                'note' => 'These bookings have no invoice yet and keep the price they were booked at; the special price applies to new bookings only. Tell the user; a booking can be repriced in its reservation view.',
            ],
            'canApply' => $plan->canApply(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRow(Price $price): array
    {
        return [
            'id' => $price->getId(),
            'description' => $price->getDescription(),
            'amount' => round((float) $price->getPrice(), 2),
            'pricingModel' => $price->getIsFlatPrice() ? 'flat' : ($price->getIsPerRoom() ? 'per_room_night' : 'per_person_night'),
            'persons' => null !== $price->getNumberOfPersons() ? (int) $price->getNumberOfPersons() : null,
            'minStay' => null !== $price->getMinStay() ? (int) $price->getMinStay() : null,
            'roomCategories' => $this->names($price->getRoomCategories()->toArray()),
            'origins' => $this->names($price->getReservationOrigins()->toArray()),
            'allYear' => (bool) $price->getAllPeriods(),
        ];
    }

    /**
     * Which stays get which price on the shared nights, for a row with another minimum stay.
     */
    private function stayLengthSplit(Price $new, Price $other): string
    {
        $newMinStay = max(1, (int) $new->getMinStay());
        $otherMinStay = max(1, (int) $other->getMinStay());

        return $otherMinStay < $newMinStay
            ? \sprintf('Stays of %d or more nights get the new price on these nights; shorter stays keep this row.', $newMinStay)
            : \sprintf('Stays of %d or more nights keep this row; stays of %d to %d nights get the new price.', $otherMinStay, $newMinStay, $otherMinStay - 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function describePeriod(SpecialPricePlan $plan): array
    {
        return [
            'firstNight' => $plan->request->firstNight->format('Y-m-d'),
            'lastNight' => $plan->request->lastNight->format('Y-m-d'),
            'nights' => (int) $plan->request->firstNight->diff($plan->request->lastNight)->days + 1,
            'description' => $plan->request->periodDescription,
        ];
    }

    /**
     * The rates of the row's first room category and its occupancy for the period, merged into
     * ranges; null when the category has no room to price with.
     *
     * @return list<array<string, mixed>>|null
     */
    private function rates(Price $price, \DateTimeImmutable $firstNight, \DateTimeImmutable $lastNight): ?array
    {
        $category = $price->getRoomCategories()->first();
        $origin = $price->getReservationOrigins()->first();
        $persons = $price->getNumberOfPersons();
        if (!$category instanceof RoomCategory || !$origin instanceof ReservationOrigin || null === $persons) {
            return null;
        }
        $sampleRoom = $this->em->getRepository(Appartment::class)->findOneBy(['roomCategory' => $category]);
        if (!$sampleRoom instanceof Appartment) {
            return null;
        }

        return PriceTools::mergeNights($this->rateCalendarService->build(
            $sampleRoom,
            $firstNight,
            $lastNight,
            max(1, (int) $price->getMinStay()),
            [(int) $persons],
            $origin,
        ));
    }

    /**
     * @param array<RoomCategory|ReservationOrigin> $entities
     *
     * @return list<array{id: int|null, name: string|null}>
     */
    private function names(array $entities): array
    {
        return array_values(array_map(static fn (RoomCategory|ReservationOrigin $entity): array => ['id' => $entity->getId(), 'name' => $entity->getName()], $entities));
    }

    /**
     * @param array<RoomCategory|ReservationOrigin> $entities
     *
     * @return list<int|null>
     */
    private function ids(array $entities): array
    {
        return array_values(array_map(static fn (RoomCategory|ReservationOrigin $entity): ?int => $entity->getId(), $entities));
    }

    private function currentApiToken(): ApiToken
    {
        $apiToken = $this->apiTokenContext->getToken();
        if (!$apiToken instanceof ApiToken) {
            // Unreachable behind the mcp firewall; fail closed regardless.
            throw McpToolException::disabled('No access token.');
        }

        return $apiToken;
    }
}
