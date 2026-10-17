<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Pricing\PriceRuleData;
use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
use App\Entity\Enum\DayPriceSource;
use App\Entity\Enum\PriceRuleCondition;
use App\Entity\PriceRule;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\McpToolException;
use App\Mcp\Security\PreviewTokenSigner;
use App\Mcp\Support\McpInput;
use App\Repository\PriceRepository;
use App\Repository\PriceRuleRepository;
use App\Repository\SubsidiaryRepository;
use App\Security\ApiTokenContext;
use App\Service\AppSettingsService;
use App\Service\Pricing\DayPriceService;
use App\Service\Pricing\PriceCalendarService;
use App\Service\Pricing\PriceRulePreview;
use App\Service\Pricing\PriceRuleService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Prices an assistant proposes and saves only after the user confirmed the preview: day prices
 * for single nights and price rules for a dated event. Lasting rules (weekends, lead time,
 * occupancy) stay a settings task in FewohBee. Both sit behind prices:write, which only
 * administrators can grant; existing bookings keep the price they were promised.
 */
final class DynamicPriceTools
{
    private const IDEMPOTENCY_TTL = 86400;
    private const MAX_NIGHTS = 366;
    private const MAX_DAYS_AHEAD = 730;

    public function __construct(
        private readonly DayPriceService $dayPriceService,
        private readonly PriceCalendarService $calendar,
        private readonly PriceRuleService $ruleWriter,
        private readonly PriceRulePreview $rulePreview,
        private readonly PriceRuleRepository $rules,
        private readonly PriceRepository $prices,
        private readonly SubsidiaryRepository $subsidiaries,
        private readonly AppSettingsService $settings,
        private readonly PreviewTokenSigner $previewTokenSigner,
        private readonly ApiTokenContext $apiTokenContext,
        private readonly McpToolAuditor $auditor,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.mcp_write')]
        private readonly RateLimiterFactoryInterface $writeLimiter,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $nights
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'preview_day_prices',
        title: 'Preview day prices',
        description: 'Checks day prices without saving them. A day price sets the room price of one night for a room category in a branch, stated for a number of guests; other occupancies and booking origins change in proportion, and on its night it takes the place of the price rules. Day prices from an assistant are held within the configured limits (see get_price_rules). Day prices the user set by hand are kept unless overwriteManual is passed. Returns per night the current and the new price; with at least one change a previewToken valid for 10 minutes.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_WRITE)]
    public function previewDayPrices(
        #[Schema(description: 'Room category id (see get_property_overview).', minimum: 1)]
        int $roomCategoryId,
        #[Schema(
            description: 'One entry per night: {"night": "2027-03-12", "amount": 129}. The amount is the room price of the night for persons guests; null takes the day price away again, so the price list and the price rules apply.',
            items: [
                'type' => 'object',
                'properties' => [
                    'night' => ['type' => 'string'],
                    'amount' => ['type' => ['number', 'null'], 'exclusiveMinimum' => 0],
                ],
                'required' => ['night', 'amount'],
            ],
            minItems: 1,
            maxItems: self::MAX_NIGHTS,
        )]
        array $nights,
        #[Schema(type: 'integer', description: 'Branch (subsidiary) id. Required when there is more than one.')]
        ?int $objectId = null,
        #[Schema(type: 'integer', description: 'Number of guests the amounts are for. Defaults to 2 where the category has a price for 2 guests, otherwise its smallest priced occupancy.', minimum: 1)]
        ?int $persons = null,
        #[Schema(description: 'Also replace day prices the user set by hand. Only after the user saw them and agreed.')]
        bool $overwriteManual = false,
    ): array {
        $plan = $this->dayPricePlan($roomCategoryId, $nights, $objectId, $persons, $overwriteManual);
        $result = $this->renderDayPricePlan($plan);
        if (0 === $result['changes']) {
            return $result + ['nextStep' => 'Nothing would change (see skipped). Tell the user why.'];
        }

        $previewToken = $this->previewTokenSigner->sign($this->currentApiToken()->getId() ?? 0, $plan['fingerprint']);

        return $result + [
            'previewToken' => $previewToken,
            'previewTokenExpiresAt' => PreviewTokenSigner::expiresAt($previewToken)?->format(\DateTimeInterface::ATOM),
            'nextStep' => 'Show the current and the new price of each night and the skipped nights to the user. Only after the user agreed, call set_day_prices with exactly the same arguments plus previewToken.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $nights
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'set_day_prices',
        title: 'Set day prices',
        description: 'Saves the day prices exactly as previewed. Requires the previewToken from preview_day_prices and the same arguments; fails when day prices changed since the preview. Existing bookings keep their price. Calling it again with the same previewToken returns the already saved result.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_WRITE)]
    public function setDayPrices(
        #[Schema(description: 'The previewToken returned by preview_day_prices.', minLength: 10, maxLength: 200)]
        string $previewToken,
        #[Schema(description: 'Same value as in preview_day_prices.', minimum: 1)]
        int $roomCategoryId,
        #[Schema(
            description: 'Same value as in preview_day_prices.',
            items: [
                'type' => 'object',
                'properties' => [
                    'night' => ['type' => 'string'],
                    'amount' => ['type' => ['number', 'null'], 'exclusiveMinimum' => 0],
                ],
                'required' => ['night', 'amount'],
            ],
            minItems: 1,
            maxItems: self::MAX_NIGHTS,
        )]
        array $nights,
        #[Schema(type: 'integer', description: 'Same value as in preview_day_prices.')]
        ?int $objectId = null,
        #[Schema(type: 'integer', description: 'Same value as in preview_day_prices.', minimum: 1)]
        ?int $persons = null,
        #[Schema(description: 'Same value as in preview_day_prices.')]
        bool $overwriteManual = false,
    ): array {
        $apiToken = $this->currentApiToken();
        // A retried call (e.g. after a dropped connection) returns what it already saved.
        $cacheItem = $this->cache->getItem('mcp_day_prices_'.hash('sha256', $apiToken->getId().'|'.$previewToken));
        if ($cacheItem->isHit()) {
            $this->auditor->note('replay', true);

            return ['saved' => false, 'alreadySaved' => true] + (array) $cacheItem->get();
        }

        $plan = $this->dayPricePlan($roomCategoryId, $nights, $objectId, $persons, $overwriteManual);
        if (!$this->previewTokenSigner->verify($previewToken, $apiToken->getId() ?? 0, $plan['fingerprint'])) {
            throw McpToolException::invalid('The previewToken is missing, expired or does not match these arguments or the current day prices. Call preview_day_prices again and confirm the result with the user.');
        }
        if (!$this->writeLimiter->create('prices-token-'.$apiToken->getId())->consume()->isAccepted()) {
            throw McpToolException::rateLimited('Too many price changes were made with this access token within the last hour. Try again later.');
        }

        $saved = $this->dayPriceService->setNights(
            $plan['subsidiary'],
            $plan['category'],
            $plan['applicable'],
            $plan['persons'],
            DayPriceSource::ASSISTANT,
            $apiToken->getName(),
            keepManual: !$overwriteManual,
        );

        $this->auditor->note('branchId', (int) $plan['subsidiary']->getId());
        $this->auditor->note('roomCategoryId', (int) $plan['category']->getId());
        $this->auditor->note('nights', array_keys($plan['applicable']));
        $this->auditor->note('changed', $saved['changed']);

        $result = [
            'changed' => $saved['changed'],
            'keptManual' => $saved['keptManual'],
            'note' => 'Existing bookings keep the price they were booked at. Call get_rate_calendar to see the resulting prices.',
        ];
        $cacheItem->set($result)->expiresAfter(self::IDEMPOTENCY_TTL);
        $this->cache->save($cacheItem);

        return ['saved' => true, 'alreadySaved' => false] + $result;
    }

    /**
     * @param list<int>|null $objectIds
     * @param list<int>|null $roomCategoryIds
     * @param list<int>|null $weekdays
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'preview_event_rule',
        title: 'Preview event price rule',
        description: 'Checks a price rule for a dated event (trade fair, festival, holidays) without saving it: the room price changes by a percentage on the nights of the event, for all or the given branches and room categories. It adds up with the other price rules that apply on these nights and is held within the configured limits; day prices take its place on their nights. Returns on how many nights it applies, an example price before and after, the other rules in the period and a previewToken valid for 10 minutes.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_WRITE)]
    public function previewEventRule(
        #[Schema(description: 'Name of the event, shown in FewohBee, e.g. "Trade fair".', minLength: 3, maxLength: 100)]
        string $name,
        #[Schema(description: 'First night, YYYY-MM-DD.')]
        string $firstNight,
        #[Schema(description: 'Last night (inclusive), YYYY-MM-DD.')]
        string $lastNight,
        #[Schema(description: 'Change of the room price in percent: 20 raises it by 20 percent, -10 lowers it by 10 percent.', minimum: -99, maximum: 500)]
        float $percent,
        #[Schema(type: 'array', description: 'Only these branches (subsidiary ids). Omit for all.', items: ['type' => 'integer'])]
        ?array $objectIds = null,
        #[Schema(type: 'array', description: 'Only these room categories. Omit for all.', items: ['type' => 'integer'])]
        ?array $roomCategoryIds = null,
        #[Schema(type: 'array', description: 'Only nights starting on these ISO weekdays (1 = Monday ... 7 = Sunday). Omit for all.', items: ['type' => 'integer', 'minimum' => 1, 'maximum' => 7])]
        ?array $weekdays = null,
    ): array {
        [$data, $fingerprint] = $this->eventRulePlan($name, $firstNight, $lastNight, $percent, $objectIds, $roomCategoryIds, $weekdays);
        $draft = $this->ruleWriter->apply($data);
        $nights = (int) $data->firstNight?->diff($data->lastNight ?? $data->firstNight)->days + 1;
        $effect = $this->rulePreview->preview($draft, null, $data->firstNight, $nights);
        $previewToken = $this->previewTokenSigner->sign($this->currentApiToken()->getId() ?? 0, $fingerprint);

        return [
            'rule' => PriceTools::describeRule($draft),
            'nightsInPeriod' => $nights,
            'appliesOnNights' => $effect['matches'],
            'example' => null === $effect['example'] ? null : [
                'branch' => $effect['example']['room']->getObject()?->getName(),
                'roomCategory' => $effect['example']['room']->getRoomCategory()?->getName(),
                'persons' => $effect['example']['persons'],
                'night' => $effect['example']['night']->format('Y-m-d'),
                'before' => round($effect['example']['before'], 2),
                'after' => round($effect['example']['after'], 2),
                'totalPercent' => round($effect['example']['percent'], 2),
                'heldWithinLimits' => $effect['example']['limited'],
            ],
            'otherRulesInPeriod' => array_map(
                fn (PriceRule $rule): array => PriceTools::describeRule($rule),
                array_values(array_filter($this->rules->findEnabled(), fn (PriceRule $rule): bool => $this->coversAnyNight($rule, $draft))),
            ),
            'limits' => $this->limits(),
            'note' => 'Existing bookings keep the price they were booked at; the rule applies to new bookings.',
            'previewToken' => $previewToken,
            'previewTokenExpiresAt' => PreviewTokenSigner::expiresAt($previewToken)?->format(\DateTimeInterface::ATOM),
            'nextStep' => 'Show the rule, the example and the other rules in the period to the user. Only after the user agreed, call create_event_rule with exactly the same arguments plus previewToken.',
        ];
    }

    /**
     * @param list<int>|null $objectIds
     * @param list<int>|null $roomCategoryIds
     * @param list<int>|null $weekdays
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_event_rule',
        title: 'Create event price rule',
        description: 'Saves the event price rule exactly as previewed. Requires the previewToken from preview_event_rule and the same arguments. The user can change or delete the rule in FewohBee. Calling it again with the same previewToken returns the already saved rule.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::PRICES_WRITE)]
    public function createEventRule(
        #[Schema(description: 'The previewToken returned by preview_event_rule.', minLength: 10, maxLength: 200)]
        string $previewToken,
        #[Schema(description: 'Same value as in preview_event_rule.', minLength: 3, maxLength: 100)]
        string $name,
        #[Schema(description: 'Same value as in preview_event_rule.')]
        string $firstNight,
        #[Schema(description: 'Same value as in preview_event_rule.')]
        string $lastNight,
        #[Schema(description: 'Same value as in preview_event_rule.', minimum: -99, maximum: 500)]
        float $percent,
        #[Schema(type: 'array', description: 'Same value as in preview_event_rule.', items: ['type' => 'integer'])]
        ?array $objectIds = null,
        #[Schema(type: 'array', description: 'Same value as in preview_event_rule.', items: ['type' => 'integer'])]
        ?array $roomCategoryIds = null,
        #[Schema(type: 'array', description: 'Same value as in preview_event_rule.', items: ['type' => 'integer', 'minimum' => 1, 'maximum' => 7])]
        ?array $weekdays = null,
    ): array {
        $apiToken = $this->currentApiToken();
        $cacheItem = $this->cache->getItem('mcp_event_rule_'.hash('sha256', $apiToken->getId().'|'.$previewToken));
        if ($cacheItem->isHit()) {
            $this->auditor->note('replay', true);

            return ['created' => false, 'alreadyCreated' => true] + (array) $cacheItem->get();
        }

        [$data, $fingerprint] = $this->eventRulePlan($name, $firstNight, $lastNight, $percent, $objectIds, $roomCategoryIds, $weekdays);
        if (!$this->previewTokenSigner->verify($previewToken, $apiToken->getId() ?? 0, $fingerprint)) {
            throw McpToolException::invalid('The previewToken is missing, expired or does not match these arguments. Call preview_event_rule again and confirm the result with the user.');
        }
        if (!$this->writeLimiter->create('prices-token-'.$apiToken->getId())->consume()->isAccepted()) {
            throw McpToolException::rateLimited('Too many price changes were made with this access token within the last hour. Try again later.');
        }

        $rule = $this->ruleWriter->save($data);
        $this->auditor->note('priceRuleId', (int) $rule->getId());
        $this->auditor->note('firstNight', $firstNight);
        $this->auditor->note('lastNight', $lastNight);

        $result = ['rule' => PriceTools::describeRule($rule)];
        $cacheItem->set($result)->expiresAfter(self::IDEMPOTENCY_TTL);
        $this->cache->save($cacheItem);

        return ['created' => true, 'alreadyCreated' => false] + $result;
    }

    /**
     * Validates the day price request and compares it with the current prices.
     *
     * @param list<array<string, mixed>> $nights
     *
     * @return array{subsidiary: Subsidiary, category: RoomCategory, persons: int, rows: list<array<string, mixed>>, applicable: array<string, float|null>, fingerprint: string}
     */
    private function dayPricePlan(int $roomCategoryId, array $nights, ?int $objectId, ?int $persons, bool $overwriteManual): array
    {
        $subsidiary = $this->branch($objectId);
        $category = $this->em->getRepository(RoomCategory::class)->find($roomCategoryId)
            ?? throw McpToolException::invalid('Unknown room category id.');
        $occupancies = $this->prices->findOccupanciesForRoomCategory($category);
        if ([] === $occupancies) {
            throw McpToolException::invalid('This room category has no room prices.');
        }
        $persons ??= \in_array(2, $occupancies, true) ? 2 : $occupancies[0];
        if (!\in_array($persons, $occupancies, true)) {
            throw McpToolException::invalid(\sprintf("This room category has no room price for %d guests. Pass 'persons' as one of: %s.", $persons, implode(', ', $occupancies)));
        }

        if ([] === $nights || \count($nights) > self::MAX_NIGHTS) {
            throw McpToolException::invalid(\sprintf("'nights' needs 1 to %d entries.", self::MAX_NIGHTS));
        }
        $amounts = [];
        $today = $this->clock->now()->setTime(0, 0);
        $latest = $today->modify('+'.self::MAX_DAYS_AHEAD.' days');
        foreach ($nights as $entry) {
            if (!\is_array($entry)) {
                throw McpToolException::invalid("Each entry of 'nights' is an object with night and amount.");
            }
            $night = McpInput::date((string) ($entry['night'] ?? ''), 'nights[].night');
            $amount = $entry['amount'] ?? null;
            if (null !== $amount && (!is_numeric($amount) || (float) $amount <= 0.0 || (float) $amount >= 100000.0)) {
                throw McpToolException::invalid('Each amount must be a positive number below 100000, or null.');
            }
            if ($night > $latest || isset($amounts[$night->format('Y-m-d')])) {
                throw McpToolException::invalid(\sprintf('Each night may appear once and lie at most %d days ahead.', self::MAX_DAYS_AHEAD));
            }
            $amounts[$night->format('Y-m-d')] = null === $amount ? null : round((float) $amount, 2);
        }
        ksort($amounts);

        if (null === $this->calendar->sampleRoom($subsidiary, $category)) {
            throw McpToolException::invalid('This branch has no room of this room category.');
        }

        $rows = [];
        $applicable = [];
        $state = [];
        $preview = $this->dayPriceService->preview($subsidiary, $category, $amounts, $persons, DayPriceSource::ASSISTANT, $this->currentApiToken()->getName(), keepManual: !$overwriteManual);
        foreach ($preview as $row) {
            $current = $row['current'];
            $state[$row['night']] = null === $current ? null : [$current->getAmount(), $current->getPersons(), $current->getSource()->value];
            unset($row['current']);
            $rows[] = $row;
            if (null === $row['skipped']) {
                $applicable[$row['night']] = $row['newDayPrice'];
            }
        }

        $fingerprint = hash('sha256', (string) json_encode([
            'branch' => $subsidiary->getId(),
            'category' => $category->getId(),
            'persons' => $persons,
            'amounts' => $amounts,
            'overwriteManual' => $overwriteManual,
            'current' => $state,
        ]));

        return ['subsidiary' => $subsidiary, 'category' => $category, 'persons' => $persons, 'rows' => $rows, 'applicable' => $applicable, 'fingerprint' => $fingerprint];
    }

    /**
     * @param array{subsidiary: Subsidiary, category: RoomCategory, persons: int, rows: list<array<string, mixed>>, applicable: array<string, float|null>, fingerprint: string} $plan
     *
     * @return array<string, mixed>
     */
    private function renderDayPricePlan(array $plan): array
    {
        return [
            'branch' => ['id' => $plan['subsidiary']->getId(), 'name' => $plan['subsidiary']->getName()],
            'roomCategory' => ['id' => $plan['category']->getId(), 'name' => $plan['category']->getName()],
            'persons' => $plan['persons'],
            'nights' => $plan['rows'],
            'changes' => \count($plan['applicable']),
            'skipped' => \count($plan['rows']) - \count($plan['applicable']),
            'limits' => $this->limits(),
            'note' => 'Prices are room prices of one night for persons guests, through the origin day prices are stated for. Existing bookings keep the price they were booked at.',
        ];
    }

    /**
     * @param list<int>|null $objectIds
     * @param list<int>|null $roomCategoryIds
     * @param list<int>|null $weekdays
     *
     * @return array{0: PriceRuleData, 1: string}
     */
    private function eventRulePlan(string $name, string $firstNight, string $lastNight, float $percent, ?array $objectIds, ?array $roomCategoryIds, ?array $weekdays): array
    {
        $name = trim($name);
        $first = McpInput::date($firstNight, 'firstNight');
        $last = McpInput::date($lastNight, 'lastNight');
        $today = $this->clock->now()->setTime(0, 0);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 100) {
            throw McpToolException::invalid("'name' needs 3 to 100 characters.");
        }
        if ($last < $first || $first < $today || $last > $today->modify('+'.self::MAX_DAYS_AHEAD.' days') || $first->diff($last)->days >= self::MAX_NIGHTS) {
            throw McpToolException::invalid(\sprintf('The event must lie between today and %d days ahead, last night not before the first, at most %d nights.', self::MAX_DAYS_AHEAD, self::MAX_NIGHTS));
        }
        if (0.0 === round($percent, 2) || $percent < -99 || $percent > 500) {
            throw McpToolException::invalid("'percent' must be between -99 and 500 and not 0.");
        }
        $weekdays = null === $weekdays ? range(1, 7) : array_values(array_unique(array_map('intval', $weekdays)));
        if ([] === $weekdays || array_any($weekdays, static fn (int $day): bool => $day < 1 || $day > 7)) {
            throw McpToolException::invalid("'weekdays' holds ISO weekdays from 1 (Monday) to 7 (Sunday).");
        }

        $data = new PriceRuleData();
        $data->name = $name;
        $data->condition = PriceRuleCondition::ALWAYS;
        $data->raise = $percent > 0;
        $data->amount = abs(round($percent, 2));
        $data->weekdays = $weekdays;
        $data->firstNight = $first;
        $data->lastNight = $last;
        $data->allSubsidiaries = null === $objectIds || [] === $objectIds;
        $data->subsidiaries = $data->allSubsidiaries ? [] : $this->entities(Subsidiary::class, $objectIds, 'objectIds');
        $data->allCategories = null === $roomCategoryIds || [] === $roomCategoryIds;
        $data->categories = $data->allCategories ? [] : $this->entities(RoomCategory::class, $roomCategoryIds, 'roomCategoryIds');

        $fingerprint = hash('sha256', (string) json_encode([
            $name, $first->format('Y-m-d'), $last->format('Y-m-d'), round($percent, 2),
            array_map(static fn (Subsidiary $s): ?int => $s->getId(), $data->subsidiaries),
            array_map(static fn (RoomCategory $c): ?int => $c->getId(), $data->categories),
            $weekdays,
        ]));

        return [$data, $fingerprint];
    }

    /**
     * @template T of Subsidiary|RoomCategory
     *
     * @param class-string<T> $class
     * @param list<int>       $ids
     *
     * @return list<T>
     */
    private function entities(string $class, array $ids, string $parameter): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $found = $this->em->getRepository($class)->findBy(['id' => $ids]);
        if (\count($found) !== \count($ids)) {
            throw McpToolException::invalid(\sprintf("'%s' contains an unknown id.", $parameter));
        }

        return array_values($found);
    }

    /** The branch of the request; without an id only when there is just one. */
    private function branch(?int $objectId): Subsidiary
    {
        if (null !== $objectId) {
            return $this->subsidiaries->find($objectId) ?? throw McpToolException::invalid('Unknown branch (objectId).');
        }
        $all = $this->subsidiaries->findAllOrdered();
        if (1 !== \count($all)) {
            throw McpToolException::invalid("Pass 'objectId': there is more than one branch (see get_property_overview).");
        }

        return $all[0];
    }

    /** Whether the rule's dates and weekdays cover at least one night of the draft. */
    private function coversAnyNight(PriceRule $rule, PriceRule $draft): bool
    {
        $last = $draft->getEndDate() ?? $draft->getStartDate();
        for ($night = $draft->getStartDate(); null !== $night && $night < $last; $night = $night->modify('+1 day')) {
            if ($rule->coversNight($night) && $draft->coversNight($night)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{minPercent: int, maxPercent: int, rounding: string} */
    private function limits(): array
    {
        $settings = $this->settings->getSettings();

        return [
            'minPercent' => $settings->getPriceChangeMinPercent(),
            'maxPercent' => $settings->getPriceChangeMaxPercent(),
            'rounding' => $settings->getPriceChangeRounding()->value,
        ];
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
