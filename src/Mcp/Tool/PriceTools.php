<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Enum\ApiScope;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Support\StayInput;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\PriceQuoteService;
use App\Service\Api\StayParameterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Price information calculated by the same pipeline as invoices.
 */
final class PriceTools
{
    public function __construct(
        private readonly PriceQuoteService $priceQuoteService,
        private readonly StayParameterResolver $stayParameterResolver,
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
}
