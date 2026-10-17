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

use App\Dto\Api\ReservationDto;
use App\Repository\InvoiceRepository;
use App\Security\Voter\ApiScopeVoter;
use App\Service\Api\InvoiceDtoBuilder;
use App\Service\Api\ReservationQueryService;
use App\Service\ReservationNameResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/v1')]
class ReservationApiController extends AbstractController
{
    public function __construct(
        private readonly ReservationQueryService $reservationQueryService,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly ReservationNameResolver $nameResolver,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('/reservations', name: 'api.reservations.list', methods: ['GET'])]
    #[IsGranted('API_SCOPE_RESERVATIONS_READ')]
    public function list(Request $request): JsonResponse
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start;
        $apartmentId = $request->query->get('apartmentId');
        $objectId = $request->query->get('objectId');

        try {
            $matched = $this->reservationQueryService->find(
                $start,
                $end,
                null !== $objectId ? (string) $objectId : null,
                null !== $apartmentId && '' !== $apartmentId ? (int) $apartmentId : null,
                // Accept both statusId=1,2 and statusId[]=1&statusId[]=2.
                $request->query->all()['statusId'] ?? null,
                $request->query->get('type'),
            );
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        // Linked invoices are only disclosed to tokens that may read invoices;
        // null (instead of an empty list) tells the caller the scope is missing.
        $invoicesByReservation = null;
        if ($this->isGranted(ApiScopeVoter::INVOICES_READ)) {
            $invoicesByReservation = $this->invoiceRepository->findSummariesByReservationIds(
                array_map(static fn (array $row): int => (int) $row[0]->getId(), $matched)
            );
        }

        $dtos = [];
        foreach ($matched as [$reservation, $types]) {
            $dtos[] = ReservationDto::fromEntity(
                $reservation,
                $types,
                $this->nameResolver->resolve($reservation),
                null === $invoicesByReservation
                    ? null
                    : InvoiceDtoBuilder::mapSummaries($invoicesByReservation[(int) $reservation->getId()] ?? [])
            );
        }

        return JsonResponse::fromJsonString($this->serializer->serialize([
            'data' => $dtos,
            'meta' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'count' => \count($dtos),
            ],
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
}
