<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Dto\Reservation\BookerData;
use App\Dto\Reservation\ReservationBookingRequest;
use App\Entity\ApiToken;
use App\Entity\Enum\ApiScope;
use App\Entity\Price;
use App\Entity\Reservation;
use App\Event\AssistantReservationCreatedEvent;
use App\Exception\ReservationBookingException;
use App\Mcp\Security\McpDataFilter;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolAuditor;
use App\Mcp\Security\McpToolException;
use App\Mcp\Security\PreviewTokenSigner;
use App\Mcp\Support\ReservationView;
use App\Mcp\Support\StayInput;
use App\Security\ApiTokenContext;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\PriceQuoteService;
use App\Service\Api\StayParameterResolver;
use App\Service\Mcp\McpSettings;
use App\Service\Reservation\ReservationBookingService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Creating reservations: preview_reservation validates and prices a booking and hands out a
 * short-lived confirmation token; create_reservation books exactly that request.
 *
 * Guarded by the reservations:write scope, the administrator switch for write access and a
 * per-token hourly limit. Every created reservation raises AssistantReservationCreatedEvent,
 * which notifies staff like a booking from a portal.
 */
final class BookingTools
{
    private const IDEMPOTENCY_TTL = 86400;

    public function __construct(
        private readonly ReservationBookingService $bookingService,
        private readonly PriceQuoteService $priceQuoteService,
        private readonly StayParameterResolver $stayParameterResolver,
        private readonly McpSettings $mcpSettings,
        private readonly PreviewTokenSigner $previewTokenSigner,
        private readonly ApiTokenContext $apiTokenContext,
        private readonly McpToolAuditor $auditor,
        private readonly McpDataFilter $dataFilter,
        private readonly ReservationView $reservationView,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.mcp_write')]
        private readonly RateLimiterFactoryInterface $writeLimiter,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @param array<string, int> $guestCounts
     * @param list<int>|null     $extras
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'preview_reservation',
        title: 'Preview reservation',
        description: 'Checks a booking of one room without saving it: availability, beds, guest rules and price. Returns warnings and a previewToken valid for 10 minutes. Show the details and price to the user before calling create_reservation.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RESERVATIONS_WRITE)]
    public function preview(
        #[Schema(description: 'Room (apartment) id.', minimum: 1)]
        int $apartmentId,
        #[Schema(description: 'Arrival date, YYYY-MM-DD.')]
        string $arrival,
        #[Schema(description: 'Departure date, YYYY-MM-DD.')]
        string $departure,
        #[Schema(description: 'Reservation status id (see get_property_overview).', minimum: 1)]
        int $statusId,
        #[Schema(type: 'integer', description: 'Guests who need a bed. Derived from guestCounts when omitted.', minimum: 1)]
        ?int $persons = null,
        #[Schema(description: 'Guests per guest category id, e.g. {"1": 2, "3": 1}.', type: 'object', additionalProperties: ['type' => 'integer', 'minimum' => 0])]
        array $guestCounts = [],
        #[Schema(type: 'integer', description: 'Booking origin id. Defaults to the online booking origin.')]
        ?int $originId = null,
        #[Schema(type: 'integer', description: 'Existing customer id from search_guests (needs permission to share guest data). Otherwise pass the booker fields.')]
        ?int $customerId = null,
        #[Schema(type: 'string', description: 'Last name of the booker (required without customerId).', maxLength: 45)]
        ?string $bookerLastname = null,
        #[Schema(type: 'string', description: 'First name of the booker.', maxLength: 45)]
        ?string $bookerFirstname = null,
        #[Schema(type: 'string', description: 'Salutation as configured in FewohBee, e.g. "Mr" or "Ms".', maxLength: 20)]
        ?string $bookerSalutation = null,
        #[Schema(type: 'string', description: 'Email of the booker. An existing customer with this email is linked instead of creating a new one.', maxLength: 180)]
        ?string $bookerEmail = null,
        #[Schema(type: 'string', description: 'Phone number of the booker.', maxLength: 50)]
        ?string $bookerPhone = null,
        #[Schema(type: 'string', description: 'Internal remark stored on the reservation.', maxLength: 1000)]
        ?string $remark = null,
        #[Schema(type: 'array', description: 'Ids of extras to book, e.g. breakfast or a dog (see availableExtras in a previous preview or extras[].id of get_price_quote). Omit to get the extras staff get preselected; pass [] for none.', items: ['type' => 'integer'])]
        ?array $extras = null,
    ): array {
        $this->assertWriteAllowed();
        [$request, $stay] = $this->buildRequest($apartmentId, $arrival, $departure, $statusId, $persons, $guestCounts, $originId, $customerId, $bookerLastname, $bookerFirstname, $bookerSalutation, $bookerEmail, $bookerPhone, $remark, $extras);

        try {
            $preview = $this->bookingService->preview($request);
        } catch (ReservationBookingException $e) {
            throw McpToolException::invalid($e->getMessage());
        }

        $quote = $this->priceQuoteService->quote(
            $preview->apartment,
            $stay->arrival,
            $stay->departure,
            $preview->persons,
            $preview->guestCounts,
            $preview->origin,
            $this->authorizationChecker->isGranted(ApiScopeVoter::TOURIST_TAX_READ),
        );
        $previewToken = $this->previewTokenSigner->sign($this->currentApiToken()->getId() ?? 0, $request->fingerprint());

        /** @var array<string, mixed> $price */
        $price = json_decode((string) json_encode($quote), true);
        $extras = $this->splitExtras($price, $preview->extras);
        // The quote's own extras total only covers online-mandatory extras; the preview states the booked ones instead.
        unset($price['extras'], $price['extrasTotal'], $price['grandTotal']);
        $touristTax = \is_array($price['touristTax'] ?? null) ? (float) ($price['touristTax']['total'] ?? 0) : 0.0;

        return [
            'available' => true,
            'apartment' => ['id' => $preview->apartment->getId(), 'number' => $preview->apartment->getNumber(), 'description' => $preview->apartment->getDescription()],
            'arrival' => $stay->arrival->format('Y-m-d'),
            'departure' => $stay->departure->format('Y-m-d'),
            'nights' => $stay->nights(),
            'persons' => $preview->persons,
            'guestCounts' => $preview->guestCounts,
            'status' => ['id' => $preview->status->getId(), 'name' => $preview->status->getName()],
            'origin' => ['id' => $preview->origin->getId(), 'name' => $preview->origin->getName()],
            'price' => $price,
            'bookedExtras' => $extras['booked'],
            'availableExtras' => $extras['available'],
            'extrasTotal' => $extras['total'],
            // Room + booked extras (+ tourist tax when the token may read it). The invoice stays authoritative.
            'estimatedTotal' => round((float) ($price['room']['gross'] ?? 0) + $extras['total'] + $touristTax, 2),
            'warnings' => $preview->warnings,
            'previewToken' => $previewToken,
            'previewTokenExpiresAt' => PreviewTokenSigner::expiresAt($previewToken)?->format(\DateTimeInterface::ATOM),
            'nextStep' => 'Show these details, the extras and the price to the user. Only after the user agreed, call create_reservation with exactly the same arguments plus previewToken.',
        ];
    }

    /**
     * @param array<string, int> $guestCounts
     * @param list<int>|null     $extras
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'create_reservation',
        title: 'Create reservation',
        description: 'Books the room exactly as previewed. Requires the previewToken from preview_reservation and the same arguments. Staff are notified in FewohBee. Calling it again with the same previewToken returns the already created reservation.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::RESERVATIONS_WRITE)]
    public function create(
        #[Schema(description: 'The previewToken returned by preview_reservation.', minLength: 10, maxLength: 200)]
        string $previewToken,
        #[Schema(description: 'Room (apartment) id.', minimum: 1)]
        int $apartmentId,
        #[Schema(description: 'Arrival date, YYYY-MM-DD.')]
        string $arrival,
        #[Schema(description: 'Departure date, YYYY-MM-DD.')]
        string $departure,
        #[Schema(description: 'Reservation status id.', minimum: 1)]
        int $statusId,
        #[Schema(type: 'integer', description: 'Same value as in preview_reservation.', minimum: 1)]
        ?int $persons = null,
        #[Schema(description: 'Same value as in preview_reservation.', type: 'object', additionalProperties: ['type' => 'integer', 'minimum' => 0])]
        array $guestCounts = [],
        #[Schema(type: 'integer', description: 'Same value as in preview_reservation.')]
        ?int $originId = null,
        #[Schema(type: 'integer', description: 'Same value as in preview_reservation.')]
        ?int $customerId = null,
        #[Schema(type: 'string', description: 'Same value as in preview_reservation.', maxLength: 45)]
        ?string $bookerLastname = null,
        #[Schema(type: 'string', description: 'Same value as in preview_reservation.', maxLength: 45)]
        ?string $bookerFirstname = null,
        #[Schema(type: 'string', description: 'Same value as in preview_reservation.', maxLength: 20)]
        ?string $bookerSalutation = null,
        #[Schema(type: 'string', description: 'Same value as in preview_reservation.', maxLength: 180)]
        ?string $bookerEmail = null,
        #[Schema(type: 'string', description: 'Same value as in preview_reservation.', maxLength: 50)]
        ?string $bookerPhone = null,
        #[Schema(type: 'string', description: 'Same value as in preview_reservation.', maxLength: 1000)]
        ?string $remark = null,
        #[Schema(type: 'array', description: 'Same value as in preview_reservation.', items: ['type' => 'integer'])]
        ?array $extras = null,
    ): array {
        $this->assertWriteAllowed();
        [$request] = $this->buildRequest($apartmentId, $arrival, $departure, $statusId, $persons, $guestCounts, $originId, $customerId, $bookerLastname, $bookerFirstname, $bookerSalutation, $bookerEmail, $bookerPhone, $remark, $extras);

        $apiToken = $this->currentApiToken();
        if (!$this->previewTokenSigner->verify($previewToken, $apiToken->getId() ?? 0, $request->fingerprint())) {
            throw McpToolException::invalid('The previewToken is missing, expired or does not match these arguments. Call preview_reservation again and confirm the result with the user.');
        }

        // A retried call (e.g. after a dropped connection) returns the reservation it already created.
        $cacheItem = $this->cache->getItem('mcp_booking_'.hash('sha256', $previewToken));
        if ($cacheItem->isHit()) {
            $existing = $this->em->getRepository(Reservation::class)->find((int) $cacheItem->get());
            if ($existing instanceof Reservation) {
                $this->auditor->note('reservationId', (int) $existing->getId());
                $this->auditor->note('replay', true);

                return ['created' => false, 'alreadyCreated' => true, 'reservation' => $this->reservationView->render($existing, withExtras: true)];
            }
        }

        if (!$this->writeLimiter->create('token-'.$apiToken->getId())->consume()->isAccepted()) {
            throw McpToolException::rateLimited('Too many reservations were created with this access token within the last hour. Try again later.');
        }

        try {
            $reservation = $this->bookingService->create($request);
        } catch (ReservationBookingException $e) {
            throw McpToolException::invalid($e->getMessage());
        }

        $cacheItem->set((int) $reservation->getId())->expiresAfter(self::IDEMPOTENCY_TTL);
        $this->cache->save($cacheItem);

        $this->auditor->note('reservationId', (int) $reservation->getId());
        $this->auditor->note('apartmentId', $apartmentId);
        $this->auditor->note('arrival', $request->arrival->format('Y-m-d'));
        $this->auditor->note('departure', $request->departure->format('Y-m-d'));
        $this->auditor->note('persons', $reservation->getPersons());
        $this->auditor->note('extras', array_map(static fn (Price $price): int => (int) $price->getId(), $reservation->getPrices()->toArray()));

        $this->eventDispatcher->dispatch(new AssistantReservationCreatedEvent($reservation, $reservation->getBooker(), $apiToken->getTokenPrefix()));

        return ['created' => true, 'alreadyCreated' => false, 'reservation' => $this->reservationView->render($reservation, withExtras: true)];
    }

    /**
     * Splits the extras of the price quote (every misc price that applies to the stay, priced for
     * it) into those that will be booked and those that could be added.
     *
     * @param array<string, mixed> $quote
     * @param list<Price>          $booked
     *
     * @return array{booked: list<array<string, mixed>>, available: list<array<string, mixed>>, total: float}
     */
    private function splitExtras(array $quote, array $booked): array
    {
        $bookedIds = array_map(static fn (Price $price): int => (int) $price->getId(), $booked);
        $result = ['booked' => [], 'available' => [], 'total' => 0.0];

        foreach ((array) ($quote['extras'] ?? []) as $row) {
            $entry = [
                'id' => (int) $row['id'],
                'description' => $row['description'] ?? null,
                'calculationType' => $row['calculationType'] ?? null,
                'unitPrice' => $row['unitPrice'] ?? null,
                'total' => $row['total'] ?? null,
            ];
            if (\in_array($entry['id'], $bookedIds, true)) {
                $result['booked'][] = $entry;
                $result['total'] += (float) ($entry['total'] ?? 0);
            } else {
                $result['available'][] = $entry;
            }
        }
        $result['total'] = round($result['total'], 2);

        return $result;
    }

    private function assertWriteAllowed(): void
    {
        if (!$this->mcpSettings->isWriteAllowed()) {
            throw McpToolException::disabled('Creating reservations through AI assistants is switched off in the FewohBee settings.');
        }
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

    /**
     * @param array<int|string, mixed> $guestCounts
     * @param list<mixed>|null         $extras
     *
     * @return array{0: ReservationBookingRequest, 1: StayInput}
     */
    private function buildRequest(
        int $apartmentId,
        string $arrival,
        string $departure,
        int $statusId,
        ?int $persons,
        array $guestCounts,
        ?int $originId,
        ?int $customerId,
        ?string $bookerLastname,
        ?string $bookerFirstname,
        ?string $bookerSalutation,
        ?string $bookerEmail,
        ?string $bookerPhone,
        ?string $remark,
        ?array $extras,
    ): array {
        $stay = StayInput::resolve($this->em, $this->stayParameterResolver, $apartmentId, $arrival, $departure, $persons, $guestCounts, $originId);

        if (null !== $customerId && !$this->dataFilter->mayShareGuestData()) {
            throw McpToolException::invalid('Referencing an existing customer needs the permission to share guest data (guests:read). Pass the booker fields instead.');
        }

        $booker = null;
        if (null === $customerId && null !== $bookerLastname) {
            $booker = new BookerData(
                trim($bookerLastname),
                self::blankToNull($bookerFirstname),
                self::blankToNull($bookerSalutation),
                self::blankToNull($bookerEmail),
                self::blankToNull($bookerPhone),
            );
        }

        $request = new ReservationBookingRequest(
            apartmentId: (int) $stay->apartment->getId(),
            arrival: $stay->arrival,
            departure: $stay->departure,
            persons: $stay->persons,
            guestCounts: $stay->guestCounts,
            statusId: $statusId,
            originId: (int) $stay->origin->getId(),
            customerId: $customerId,
            booker: $booker,
            remark: self::blankToNull($remark),
            extraPriceIds: null === $extras ? null : array_values(array_unique(array_map('intval', $extras))),
        );

        return [$request, $stay];
    }

    private static function blankToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
