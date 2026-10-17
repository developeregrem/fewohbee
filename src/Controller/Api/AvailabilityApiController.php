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

use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Service\AvailabilityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Room counts per night - rooms, booked, blocked, free - without any reservation data, e.g. for
 * a pricing tool. Tokens that may read reservations may read this as well.
 */
#[Route('/api/v1/availability')]
#[IsGranted(new Expression("is_granted('API_SCOPE_AVAILABILITY_READ') or is_granted('API_SCOPE_RESERVATIONS_READ')"))]
class AvailabilityApiController extends AbstractController
{
    /** Matches the full-sync window channel managers ask for. */
    private const MAX_DAYS = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    #[Route('', name: 'api.availability', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? new \DateTimeImmutable('today');
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start;
        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range must not exceed %d days.', self::MAX_DAYS));
        }
        $subsidiary = $this->find(Subsidiary::class, $request->query->get('objectId'), 'objectId');
        $category = $this->find(RoomCategory::class, $request->query->get('roomCategoryId'), 'roomCategoryId');

        $nights = [];
        foreach ($this->availabilityService->getRoomNightsPerDay($subsidiary?->getId() ?? 'all', $category?->getId(), $start, $end->modify('+1 day')) as $date => $counts) {
            $nights[] = ['date' => $date] + $counts;
        }

        return new JsonResponse([
            'data' => $nights,
            'meta' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'objectId' => $subsidiary?->getId(),
                'roomCategoryId' => $category?->getId(),
                'count' => \count($nights),
            ],
        ]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(string $class, ?string $id, string $paramName): ?object
    {
        if (null === $id || '' === $id) {
            return null;
        }

        return $this->em->getRepository($class)->find((int) $id)
            ?? throw new BadRequestHttpException(sprintf("Unknown '%s'.", $paramName));
    }

    private function parseDate(?string $value, string $paramName): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m-d.", $paramName));
        }

        return $parsed;
    }
}
