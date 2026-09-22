<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Enum\ApiScope;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Mcp\Security\McpDataFilter;
use App\Mcp\Security\McpRequiresScope;
use App\Mcp\Security\McpToolException;
use App\Mcp\Support\McpInput;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Service\Api\InvoiceDtoBuilder;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Read access to invoices. Recipient (name and address), remark and payment note are only
 * included when the access token may share guest data.
 */
final class InvoiceTools
{
    private const MAX_RANGE_DAYS = 366;
    private const MAX_RESULTS = 200;

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly InvoiceDtoBuilder $invoiceDtoBuilder,
        private readonly McpDataFilter $dataFilter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'list_invoices',
        title: 'List invoices',
        description: 'Invoices dated within a range (at most 366 days) with totals, VAT breakdown and linked reservation ids.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::INVOICES_READ)]
    public function list(
        #[Schema(description: 'First invoice date, YYYY-MM-DD.')]
        string $start,
        #[Schema(type: 'string', description: 'Last invoice date (inclusive), YYYY-MM-DD. Defaults to start.')]
        ?string $end = null,
        #[Schema(type: 'string', description: 'Only invoices with this status.', enum: ['OPEN', 'PAID', 'PREPAID', 'CANCELED'])]
        ?string $status = null,
        #[Schema(description: 'Number of invoices to skip (pagination).', minimum: 0)]
        int $offset = 0,
    ): array {
        $startDate = McpInput::date($start, 'start');
        $endDate = null !== $end ? McpInput::date($end, 'end') : $startDate;
        if ($endDate < $startDate) {
            throw McpToolException::invalid("'end' must not be before 'start'.");
        }
        if ((int) $startDate->diff($endDate)->days > self::MAX_RANGE_DAYS) {
            throw McpToolException::invalid(\sprintf('The range must not exceed %d days.', self::MAX_RANGE_DAYS));
        }

        $statusFilter = [];
        if (null !== $status) {
            $case = \defined(InvoiceStatus::class.'::'.$status) ? \constant(InvoiceStatus::class.'::'.$status) : null;
            if (!$case instanceof InvoiceStatus) {
                throw McpToolException::invalid("Unknown 'status'.");
            }
            $statusFilter = [$case->value];
        }

        $invoices = $this->invoiceRepository->findForPeriod($startDate, $endDate, $statusFilter);
        $total = \count($invoices);
        $page = \array_slice($invoices, max(0, $offset), self::MAX_RESULTS);
        $refs = $this->reservationRepository->findRefsByInvoiceIds(array_map(static fn (Invoice $invoice): int => (int) $invoice->getId(), $page));

        $result = [];
        foreach ($page as $invoice) {
            $result[] = $this->render($invoice, $refs[(int) $invoice->getId()] ?? []);
        }

        return [
            'invoices' => $result,
            'total' => $total,
            'offset' => max(0, $offset),
            'hasMore' => $offset + \count($page) < $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_invoice',
        title: 'Get invoice',
        description: 'One invoice by id with totals, VAT breakdown and linked reservation ids.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    #[McpRequiresScope(ApiScope::INVOICES_READ)]
    public function get(
        #[Schema(description: 'Invoice id.', minimum: 1)]
        int $invoiceId,
    ): array {
        $invoice = $this->invoiceRepository->find($invoiceId);
        if (!$invoice instanceof Invoice) {
            throw McpToolException::invalid('Unknown invoice id.');
        }
        $refs = $this->reservationRepository->findRefsByInvoiceIds([$invoiceId]);

        return $this->render($invoice, $refs[$invoiceId] ?? []);
    }

    /**
     * @param list<array{id: int, uuid: mixed}> $reservationRows
     *
     * @return array<string, mixed>
     */
    private function render(Invoice $invoice, array $reservationRows): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) json_encode($this->invoiceDtoBuilder->build($invoice, $reservationRows)), true);
        if (!$this->dataFilter->mayShareGuestData()) {
            $data['recipient'] = null;
        }
        $data['remark'] = $this->dataFilter->untrustedText($invoice->getRemark());
        $data['payment'] = $this->dataFilter->untrustedText($invoice->getPayment());

        return $data;
    }
}
