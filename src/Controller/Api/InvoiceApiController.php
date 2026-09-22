<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Api;

use App\Dto\Api\InvoiceDto;
use App\Entity\Enum\InvoiceStatus;
use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Service\Api\InvoiceDtoBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1/invoices')]
#[IsGranted('API_SCOPE_INVOICES_READ')]
class InvoiceApiController extends AbstractController
{
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly InvoiceDtoBuilder $invoiceDtoBuilder,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('', name: 'api.invoices.list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start;

        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->format('%a') > self::MAX_RANGE_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range must not exceed %d days.', self::MAX_RANGE_DAYS));
        }

        $status = $this->resolveStatus($request);
        $invoices = $this->invoiceRepository->findForPeriod($start, $end, $status);
        $reservationRefs = $this->reservationRepository->findRefsByInvoiceIds(
            array_map(static fn (Invoice $invoice): int => (int) $invoice->getId(), $invoices)
        );

        $dtos = [];
        foreach ($invoices as $invoice) {
            $dtos[] = $this->invoiceDtoBuilder->build($invoice, $reservationRefs[(int) $invoice->getId()] ?? []);
        }

        return JsonResponse::fromJsonString($this->serializer->serialize([
            'data' => $dtos,
            'meta' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'status' => $status,
                'count' => \count($dtos),
            ],
        ], 'json'));
    }

    #[Route('/{id}', name: 'api.invoices.get', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $invoice = $this->invoiceRepository->find($id);
        if (!$invoice instanceof Invoice) {
            throw new NotFoundHttpException();
        }

        $refs = $this->reservationRepository->findRefsByInvoiceIds([$id]);

        return JsonResponse::fromJsonString($this->serializer->serialize([
            'data' => $this->invoiceDtoBuilder->build($invoice, $refs[$id] ?? []),
        ], 'json'));
    }

    private function parseDate(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m-d.", $paramName));
        }

        return $parsed->setTime(0, 0);
    }

    /**
     * @return int[] empty = no status filter
     */
    private function resolveStatus(Request $request): array
    {
        $param = $request->query->all()['status'] ?? null;
        if (null === $param || '' === $param || [] === $param) {
            return [];
        }

        $values = \is_array($param) ? $param : explode(',', (string) $param);
        $result = [];
        foreach ($values as $value) {
            $status = InvoiceStatus::fromStatus((int) $value);
            if (null === $status) {
                throw new BadRequestHttpException("Unknown 'status'.");
            }
            $result[] = $status->value;
        }

        return array_values(array_unique($result));
    }
}
