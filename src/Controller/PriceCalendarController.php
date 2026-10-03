<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RoomCategory;
use App\Entity\Subsidiary;
use App\Repository\AppartmentRepository;
use App\Repository\PriceRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\Pricing\DayPriceService;
use App\Service\Pricing\PriceCalendarService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The price calendar tab of the price settings: the room price per night for one room category
 * in one subsidiary, and day prices set from there.
 */
#[Route('/settings/prices/calendar')]
#[IsGranted('ROLE_ADMIN')]
final class PriceCalendarController extends AbstractController
{
    /** The last selection, shown again when the calendar is opened through its tab. */
    private const SESSION_SELECTION = 'price_calendar_selection';

    public function __construct(
        private readonly SubsidiaryRepository $subsidiaries,
        private readonly AppartmentRepository $apartments,
        private readonly PriceRepository $prices,
        private readonly PriceCalendarService $calendar,
    ) {
    }

    #[Route('/', name: 'prices.calendar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $session = $request->getSession();
        /** @var array{subsidiary?: int, category?: int, persons?: int, month?: string} $selection */
        $selection = 0 === $request->query->count() ? $session->get(self::SESSION_SELECTION, []) : $request->query->all();

        $subsidiaries = $this->subsidiaries->findAllOrdered();
        $subsidiary = $this->pick($subsidiaries, (int) ($selection['subsidiary'] ?? 0), strict: true);
        // Without a choice, the first subsidiary that has rooms to price.
        foreach (null === $subsidiary ? $subsidiaries : [] as $candidate) {
            if ([] !== $this->categoriesOf($candidate)) {
                $subsidiary = $candidate;
                break;
            }
        }
        $subsidiary ??= $subsidiaries[0] ?? null;
        $categories = null === $subsidiary ? [] : $this->categoriesOf($subsidiary);
        $category = $this->pick($categories, (int) ($selection['category'] ?? 0));
        $occupancies = null === $category ? [] : $this->prices->findOccupanciesForRoomCategory($category);
        $persons = $this->persons($occupancies, (int) ($selection['persons'] ?? 0));

        $month = \DateTimeImmutable::createFromFormat('!Y-m', (string) ($selection['month'] ?? '')) ?: null;
        $thisMonth = new \DateTimeImmutable('first day of this month midnight');
        $month = null === $month || $month < $thisMonth ? $thisMonth : $month;
        $nextMonth = $month->modify('+1 month');

        $session->set(self::SESSION_SELECTION, [
            'subsidiary' => $subsidiary?->getId(),
            'category' => $category?->getId(),
            'persons' => $persons,
            'month' => $month->format('Y-m'),
        ]);

        return $this->render('PriceCalendar/index.html.twig', [
            'subsidiaries' => $subsidiaries,
            'subsidiary' => $subsidiary,
            'categories' => $categories,
            'category' => $category,
            'occupancies' => $occupancies,
            'persons' => $persons,
            'month' => $month,
            'previousMonth' => $month > $thisMonth ? $month->modify('-1 month') : null,
            'nextMonth' => $nextMonth,
            'nights' => null === $subsidiary || null === $category || null === $persons
                ? null
                : $this->calendar->nights($subsidiary, $category, $persons, $month, $nextMonth),
        ]);
    }

    /** The offcanvas for one night: how its price comes about, and the day price form. */
    #[Route('/night', name: 'prices.calendar.night', methods: ['GET'])]
    public function night(Request $request): Response
    {
        [$subsidiary, $category, $persons, $night] = $this->requireNightContext($request->query->all());
        $nights = $this->calendar->nights($subsidiary, $category, $persons, $night, $night->modify('+1 day'));

        return $this->render('PriceCalendar/_night.html.twig', [
            'subsidiary' => $subsidiary,
            'category' => $category,
            'persons' => $persons,
            'day' => $nights[0] ?? throw $this->createNotFoundException(),
        ]);
    }

    /** Sets or removes the day price for one night or a range of nights. */
    #[Route('/night', name: 'prices.calendar.save', methods: ['POST'])]
    public function save(Request $request, DayPriceService $dayPrices, TranslatorInterface $translator): Response
    {
        if (!$this->isCsrfTokenValid('price-calendar', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $input = $request->request->all();
        [$subsidiary, $category, $persons, $first] = $this->requireNightContext($input);
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['last'] ?? '')) ?: $first;
        $weekdays = array_values(array_unique(array_map('intval', (array) ($input['weekdays'] ?? range(1, 7)))));
        $reset = 'reset' === ($input['intent'] ?? null);
        $amount = $reset ? null : (float) str_replace(',', '.', (string) ($input['amount'] ?? ''));

        $error = match (true) {
            null !== $amount && ($amount <= 0.0 || $amount >= 100000.0) => 'price_calendar.error.amount',
            $last < $first || $first->diff($last)->days >= DayPriceService::MAX_NIGHTS => 'price_calendar.error.range',
            [] === array_intersect($weekdays, range(1, 7)) => 'price_calendar.error.weekdays',
            default => null,
        };
        if (null !== $error) {
            return new Response($translator->trans($error), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dayPrices->set($subsidiary, $category, $first, $last, $weekdays, $amount, $persons);
        $this->addFlash('success', $reset ? 'price_calendar.reset' : 'price_calendar.saved');

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed> $input subsidiary, category, persons and night of the request
     *
     * @return array{0: Subsidiary, 1: RoomCategory, 2: int, 3: \DateTimeImmutable}
     */
    private function requireNightContext(array $input): array
    {
        $subsidiary = $this->subsidiaries->find((int) ($input['subsidiary'] ?? 0));
        $category = null === $subsidiary ? null : $this->pick($this->categoriesOf($subsidiary), (int) ($input['category'] ?? 0), strict: true);
        $persons = (int) ($input['persons'] ?? 0);
        $night = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['night'] ?? ''));
        if (null === $subsidiary || null === $category || $persons < 1 || $persons > 100 || false === $night) {
            throw $this->createNotFoundException();
        }

        return [$subsidiary, $category, $persons, $night];
    }

    /**
     * The room categories with an active room in the subsidiary.
     *
     * @return list<RoomCategory>
     */
    private function categoriesOf(Subsidiary $subsidiary): array
    {
        $categories = [];
        foreach ($this->apartments->findAllByProperty($subsidiary->getId()) as $room) {
            $category = $room->getRoomCategory();
            if (null !== $category) {
                $categories[$category->getId()] = $category;
            }
        }

        return array_values($categories);
    }

    /**
     * @template T of Subsidiary|RoomCategory
     *
     * @param list<T> $options
     *
     * @return T|null the option with the id, otherwise the first one (or null with $strict)
     */
    private function pick(array $options, int $id, bool $strict = false): Subsidiary|RoomCategory|null
    {
        foreach ($options as $option) {
            if ($option->getId() === $id) {
                return $option;
            }
        }

        return $strict ? null : ($options[0] ?? null);
    }

    /** @param list<int> $occupancies */
    private function persons(array $occupancies, int $requested): ?int
    {
        if (in_array($requested, $occupancies, true)) {
            return $requested;
        }

        return in_array(2, $occupancies, true) ? 2 : ($occupancies[0] ?? null);
    }
}
