<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Enum\ApiScope;
use App\Entity\Subsidiary;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Service\TouristTaxReportService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Tourist tax figures, calculated live with the same rules as invoices.
 */
final class TouristTaxTools
{
    private const MAX_MONTHS = 36;

    public function __construct(
        private readonly TouristTaxReportService $touristTaxReportService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_tourist_tax_report',
        title: 'Tourist tax report',
        description: 'Tourist tax per month (at most 36 months): overnight stays and amounts per tax and guest category, and the monthly total. Figures only, no guest data.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::TOURIST_TAX_READ)]
    public function report(
        #[Schema(description: 'First month, YYYY-MM.')]
        string $startMonth,
        #[Schema(type: 'string', description: 'Last month (inclusive), YYYY-MM. Defaults to startMonth.')]
        ?string $endMonth = null,
        #[Schema(type: 'integer', description: 'Property (object) id; all properties when omitted.')]
        ?int $objectId = null,
    ): array {
        $start = McpInput::month($startMonth, 'startMonth');
        $end = null !== $endMonth ? McpInput::month($endMonth, 'endMonth') : $start;
        if ($end < $start) {
            throw McpToolException::invalid("'endMonth' must not be before 'startMonth'.");
        }
        $months = ((int) $end->format('Y') - (int) $start->format('Y')) * 12 + ((int) $end->format('n') - (int) $start->format('n')) + 1;
        if ($months > self::MAX_MONTHS) {
            throw McpToolException::invalid(\sprintf('The range must not exceed %d months.', self::MAX_MONTHS));
        }

        $subsidiary = null;
        if (null !== $objectId) {
            $subsidiary = $this->em->getRepository(Subsidiary::class)->find($objectId);
            if (!$subsidiary instanceof Subsidiary) {
                throw McpToolException::invalid('Unknown property (object) id.');
            }
        }

        $summary = $this->touristTaxReportService->buildMonthlySummary(
            $start,
            $end->modify('last day of this month')->setTime(23, 59, 59),
            $subsidiary,
        );

        return [
            'months' => $summary['months'],
            'guestCategories' => $summary['guestCategories'],
            'objectId' => $objectId,
        ];
    }
}
