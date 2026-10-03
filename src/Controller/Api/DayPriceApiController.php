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

use App\Entity\DayPrice;
use App\Entity\Enum\DayPriceSource;
use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\DayPriceRepository;
use App\Repository\PriceRepository;
use App\Repository\SubsidiaryRepository;
use App\Security\ApiTokenContext;
use App\Security\Voter\ApiScopeVoter;
use App\Service\AppSettingsService;
use App\Service\Pricing\DayPriceService;
use App\Service\Pricing\PriceCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Day prices for programs such as pricing tools: read them, and set or remove them for the nights
 * of one room category in one subsidiary. A day price sets the room price of a night for a number
 * of guests; other occupancies and origins change in proportion. Prices from programs are held
 * within the limits of the price rules, and existing bookings keep the price they were promised.
 */
#[Route('/api/v1/day-prices')]
class DayPriceApiController extends AbstractController
{
    private const MAX_DAYS = 500;
    private const MAX_DAYS_AHEAD = 730;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DayPriceRepository $dayPrices,
        private readonly DayPriceService $dayPriceService,
        private readonly PriceCalendarService $calendar,
        private readonly PriceRepository $prices,
        private readonly SubsidiaryRepository $subsidiaries,
        private readonly AppSettingsService $settings,
        private readonly ApiTokenContext $apiTokenContext,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'limiter.api_write')]
        private readonly RateLimiterFactoryInterface $writeLimiter,
    ) {
    }

    #[Route('', name: 'api.day_prices.list', methods: ['GET'])]
    #[IsGranted(ApiScopeVoter::PRICES_READ)]
    public function list(Request $request): JsonResponse
    {
        $start = $this->parseDate($request->query->get('start'), 'start') ?? $this->clock->now()->setTime(0, 0);
        $end = $this->parseDate($request->query->get('end'), 'end') ?? $start->modify('+'.(self::MAX_DAYS - 1).' days');
        if ($end < $start) {
            throw new BadRequestHttpException("Parameter 'end' must not be before 'start'.");
        }
        if ((int) $start->diff($end)->days + 1 > self::MAX_DAYS) {
            throw new BadRequestHttpException(sprintf('Date range must not exceed %d days.', self::MAX_DAYS));
        }
        $subsidiary = $this->findOrFail(Subsidiary::class, $request->query->get('objectId'), 'objectId');
        $category = $this->findOrFail(RoomCategory::class, $request->query->get('roomCategoryId'), 'roomCategoryId');

        $data = array_map(static fn (DayPrice $dayPrice): array => [
            'objectId' => $dayPrice->getSubsidiary()->getId(),
            'roomCategoryId' => $dayPrice->getRoomCategory()->getId(),
            'night' => $dayPrice->getNight()->format('Y-m-d'),
            'amount' => $dayPrice->getAmount(),
            'persons' => $dayPrice->getPersons(),
            'source' => $dayPrice->getSource()->value,
            'sourceLabel' => $dayPrice->getSourceLabel(),
            'updatedAt' => $dayPrice->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->dayPrices->findForPeriod($subsidiary, $category, $start, $end->modify('+1 day')));

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'start' => $start->format('Y-m-d'),
                'end' => $end->format('Y-m-d'),
                'objectId' => $subsidiary?->getId(),
                'roomCategoryId' => $category?->getId(),
                'count' => \count($data),
            ],
        ]);
    }

    /**
     * Sets or removes the day prices of the given nights; nights not listed stay as they are.
     * Nothing is saved when an entry is invalid. Sending the same prices again changes nothing.
     */
    #[Route('', name: 'api.day_prices.put', methods: ['PUT'])]
    #[IsGranted(ApiScopeVoter::PRICES_WRITE)]
    public function put(Request $request): JsonResponse
    {
        // Browsers keep HTTP Basic credentials and send them along on their own.
        if (!str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ')) {
            throw new AccessDeniedHttpException('Changing prices needs a bearer token.');
        }
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('Send the day prices as application/json.');
        }
        $apiToken = $this->apiTokenContext->getToken() ?? throw new AccessDeniedHttpException();
        $limit = $this->writeLimiter->create('token-'.$apiToken->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many price changes with this token. Try again later.');
        }
        try {
            $payload = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('The request body is not valid JSON.');
        }
        if (!\is_array($payload)) {
            throw new BadRequestHttpException('The request body must be a JSON object.');
        }

        $errors = [];
        $subsidiary = $this->subsidiary($payload['objectId'] ?? null, $errors);
        $category = $this->entity(RoomCategory::class, $payload['roomCategoryId'] ?? null, 'roomCategoryId', true, $errors);
        $persons = $this->persons($category, $payload['persons'] ?? null, $errors);
        $amounts = $this->amounts($payload['nights'] ?? null, $errors);
        $overwriteManual = $payload['overwriteManual'] ?? false;
        if (!\is_bool($overwriteManual)) {
            $errors[] = ['field' => 'overwriteManual', 'message' => 'Must be true or false.'];
        }
        if (null !== $subsidiary && null !== $category && null === $this->calendar->sampleRoom($subsidiary, $category)) {
            $errors[] = ['field' => 'roomCategoryId', 'message' => 'This branch has no room of this room category.'];
        }
        if ([] !== $errors || null === $subsidiary || null === $category || null === $persons) {
            return new JsonResponse(['error' => [
                'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'Nothing was saved: the request contains invalid entries.',
                'details' => $errors,
            ]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $preview = $this->dayPriceService->preview($subsidiary, $category, $amounts, $persons, DayPriceSource::API, $apiToken->getName(), keepManual: true !== $overwriteManual);
        $applicable = [];
        $skipped = ['past' => [], 'noPrice' => [], 'manual' => []];
        $unchanged = 0;
        $limited = [];
        foreach ($preview as $row) {
            match ($row['skipped']) {
                null => $applicable[$row['night']] = $row['newDayPrice'],
                'night_is_past' => $skipped['past'][] = $row['night'],
                'no_price_for_this_night' => $skipped['noPrice'][] = $row['night'],
                'day_price_set_by_hand' => $skipped['manual'][] = $row['night'],
                default => ++$unchanged,
            };
            if ($row['heldWithinLimits'] && \in_array($row['skipped'], [null, 'unchanged'], true)) {
                $limited[] = ['night' => $row['night'], 'requested' => $row['newDayPrice'], 'applied' => $row['effectivePrice']];
            }
        }
        $saved = $this->dayPriceService->setNights($subsidiary, $category, $applicable, $persons, DayPriceSource::API, $apiToken->getName(), keepManual: true !== $overwriteManual);
        $settings = $this->settings->getSettings();

        return new JsonResponse([
            'data' => [
                'created' => $saved['created'],
                'updated' => $saved['updated'],
                'removed' => $saved['removed'],
                'unchanged' => $unchanged + $saved['unchanged'],
                'skipped' => $skipped,
                'limited' => $limited,
            ],
            'meta' => [
                'objectId' => $subsidiary->getId(),
                'roomCategoryId' => $category->getId(),
                'persons' => $persons,
                'limits' => ['minPercent' => $settings->getPriceChangeMinPercent(), 'maxPercent' => $settings->getPriceChangeMaxPercent()],
            ],
        ]);
    }

    /**
     * The branch of the request; it may be left out when there is only one.
     *
     * @param list<array{field: string, message: string}> $errors
     */
    private function subsidiary(mixed $id, array &$errors): ?Subsidiary
    {
        if (null !== $id) {
            return $this->entity(Subsidiary::class, $id, 'objectId', true, $errors);
        }
        $all = $this->subsidiaries->findAllOrdered();
        if (1 !== \count($all)) {
            $errors[] = ['field' => 'objectId', 'message' => 'Required when there is more than one branch.'];

            return null;
        }

        return $all[0];
    }

    /**
     * @template T of object
     *
     * @param class-string<T>                             $class
     * @param list<array{field: string, message: string}> $errors
     *
     * @return T|null
     */
    private function entity(string $class, mixed $id, string $field, bool $required, array &$errors): ?object
    {
        if (null === $id && !$required) {
            return null;
        }
        $entity = \is_int($id) && $id > 0 ? $this->em->getRepository($class)->find($id) : null;
        if (null === $entity) {
            $errors[] = ['field' => $field, 'message' => null === $id ? 'Required.' : 'Unknown id.'];
        }

        return $entity;
    }

    /**
     * The number of guests the amounts are for: 2 by default where the category has a price for
     * two, otherwise its smallest priced occupancy.
     *
     * @param list<array{field: string, message: string}> $errors
     */
    private function persons(?RoomCategory $category, mixed $persons, array &$errors): ?int
    {
        if (null === $category) {
            return null;
        }
        $occupancies = $this->prices->findOccupanciesForRoomCategory($category);
        if ([] === $occupancies) {
            $errors[] = ['field' => 'roomCategoryId', 'message' => 'This room category has no room prices.'];

            return null;
        }
        $persons ??= \in_array(2, $occupancies, true) ? 2 : $occupancies[0];
        if (!\is_int($persons) || !\in_array($persons, $occupancies, true)) {
            $errors[] = ['field' => 'persons', 'message' => 'Must be one of the occupancies with a room price: '.implode(', ', $occupancies).'.'];

            return null;
        }

        return $persons;
    }

    /**
     * @param list<array{field: string, message: string}> $errors
     *
     * @return array<string, float|null> keyed by night, Y-m-d
     */
    private function amounts(mixed $nights, array &$errors): array
    {
        if (!\is_array($nights) || !array_is_list($nights) || [] === $nights || \count($nights) > DayPriceService::MAX_NIGHTS_AT_ONCE) {
            $errors[] = ['field' => 'nights', 'message' => sprintf('A list of 1 to %d entries.', DayPriceService::MAX_NIGHTS_AT_ONCE)];

            return [];
        }
        $latest = $this->clock->now()->setTime(0, 0)->modify('+'.self::MAX_DAYS_AHEAD.' days');
        $amounts = [];
        foreach ($nights as $index => $entry) {
            $field = 'nights['.$index.']';
            $night = \is_array($entry) && \is_string($entry['night'] ?? null) ? $this->parseNight($entry['night']) : null;
            if (null === $night) {
                $errors[] = ['field' => $field.'.night', 'message' => 'A date in the format YYYY-MM-DD.'];
                continue;
            }
            $date = $night->format('Y-m-d');
            if ($night > $latest) {
                $errors[] = ['field' => $field.'.night', 'message' => sprintf('At most %d days ahead.', self::MAX_DAYS_AHEAD)];
            } elseif (\array_key_exists($date, $amounts)) {
                $errors[] = ['field' => $field.'.night', 'message' => 'This night is listed twice.'];
            }
            if (!\array_key_exists('amount', $entry)) {
                $errors[] = ['field' => $field.'.amount', 'message' => 'Required; null removes the day price.'];
                continue;
            }
            $amount = $entry['amount'];
            if (null !== $amount && (!(\is_int($amount) || \is_float($amount)) || $amount <= 0 || $amount >= 100000)) {
                $errors[] = ['field' => $field.'.amount', 'message' => 'A positive number below 100000, or null.'];
                continue;
            }
            $amounts[$date] = null === $amount ? null : round((float) $amount, 2);
        }

        return $amounts;
    }

    private function parseNight(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $parsed : null;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function findOrFail(string $class, ?string $id, string $paramName): ?object
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

        return $this->parseNight($value)
            ?? throw new BadRequestHttpException(sprintf("Invalid parameter '%s': expected format Y-m-d.", $paramName));
    }
}
