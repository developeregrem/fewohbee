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

namespace App\Controller;

use App\Entity\Appartment;
use App\Entity\RoomBlock;
use App\Entity\Subsidiary;
use App\Exception\RoomBlockConflictException;
use App\Repository\AppartmentRepository;
use App\Repository\RoomBlockRepository;
use App\Repository\SubsidiaryRepository;
use App\Service\RoomBlockService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_RESERVATIONS_RO')] // ROLE_RESERVATIONS is included
#[Route('/reservation/blocks')]
class RoomBlockController extends AbstractController
{
    #[Route('/', name: 'reservations.blocks.list', methods: ['GET'])]
    /** Render the block list modal, filtered by year/month/subsidiary. */
    public function listAction(Request $request, RoomBlockRepository $roomBlockRepository, SubsidiaryRepository $subsidiaryRepository): Response
    {
        return $this->renderList($request, $roomBlockRepository, $subsidiaryRepository);
    }

    #[Route('/form', name: 'reservations.blocks.form', methods: ['GET'])]
    /** Render the create form; ?fromSelection=1 prefills rooms and period from the reservation wizard session. */
    public function formAction(Request $request, RequestStack $requestStack, AppartmentRepository $appartmentRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_RESERVATIONS');

        $selectedRoomIds = [];
        $from = $request->query->get('from', '');
        $end = $request->query->get('end', '');

        if ($request->query->getBoolean('fromSelection')) {
            $reservationObjects = $requestStack->getSession()->get('reservationInCreation', []);
            $starts = [];
            $ends = [];
            foreach ($reservationObjects as $reservationObject) {
                $selectedRoomIds[] = (int) $reservationObject->getAppartmentId();
                $starts[] = strtotime((string) $reservationObject->getStart());
                $ends[] = strtotime((string) $reservationObject->getEnd());
            }
            if (count($starts) > 0) {
                $from = date('Y-m-d', min($starts));
                $end = date('Y-m-d', max($ends));
            }
        }

        return $this->render('Reservations/roomblock_form.html.twig', [
            'roomsBySubsidiary' => $this->groupRoomsBySubsidiary($appartmentRepository->findAll()),
            'selectedRoomIds' => $selectedRoomIds,
            'from' => $from,
            'end' => $end,
            'reason' => '',
            'note' => '',
            'conflicts' => [],
            'error' => null,
        ]);
    }

    #[Route('/create', name: 'reservations.blocks.create', methods: ['POST'])]
    /** Create blocks for the selected rooms; re-renders the form with the conflict list on failure. */
    public function createAction(
        Request $request,
        RoomBlockService $roomBlockService,
        AppartmentRepository $appartmentRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_RESERVATIONS');

        $roomIds = array_map('intval', $request->request->all('rooms'));
        $rooms = [] === $roomIds ? [] : $appartmentRepository->findBy(['id' => $roomIds]);
        $input = $this->readBlockInput($request);

        $renderFormWithError = fn (array $conflicts, ?string $error): Response => $this->render('Reservations/roomblock_form.html.twig', [
            'roomsBySubsidiary' => $this->groupRoomsBySubsidiary($appartmentRepository->findAll()),
            'selectedRoomIds' => $roomIds,
            'from' => $request->request->get('from', ''),
            'end' => $request->request->get('end', ''),
            'reason' => $input['reason'],
            'note' => $input['note'] ?? '',
            'conflicts' => $conflicts,
            'error' => $error,
        ]);

        if (!$this->isCsrfTokenValid('room_block', (string) $request->request->get('_token'))) {
            return $renderFormWithError([], 'flash.invalidtoken');
        }
        if (0 === count($rooms)) {
            return $renderFormWithError([], 'roomblock.error.rooms');
        }
        if (null === $input['start'] || null === $input['end']) {
            return $renderFormWithError([], 'roomblock.error.period');
        }

        try {
            $roomBlockService->createBlocks($rooms, $input['start'], $input['end'], $input['reason'], $input['note']);
        } catch (RoomBlockConflictException $e) {
            return $renderFormWithError($e->getConflicts(), null);
        } catch (\InvalidArgumentException $e) {
            return $renderFormWithError([], $e->getMessage());
        }

        // success: let the client reload the index page so the flash + table refresh (empty body)
        $this->addFlash('success', 'roomblock.flash.create.success');

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'reservations.blocks.get', methods: ['GET'], requirements: ['id' => '\d+'])]
    /** Render the details modal (read-only for RO users, edit form otherwise). */
    public function getAction(Request $request, RoomBlock $block): Response
    {
        return $this->render('Reservations/roomblock_details.html.twig', [
            'roomBlock' => $block,
            'conflicts' => [],
            'error' => null,
            // pass the list filter through so returning to the list keeps it
            'filterQuery' => $this->listFilterQuery($request),
        ]);
    }

    #[Route('/{id}/edit', name: 'reservations.blocks.edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    /** Update period/reason/note of a block. */
    public function editAction(
        Request $request,
        RoomBlock $block,
        RoomBlockService $roomBlockService,
        RoomBlockRepository $roomBlockRepository,
        SubsidiaryRepository $subsidiaryRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_RESERVATIONS');

        $input = $this->readBlockInput($request);
        $renderDetailsWithError = fn (array $conflicts, ?string $error): Response => $this->render('Reservations/roomblock_details.html.twig', [
            'roomBlock' => $block,
            'conflicts' => $conflicts,
            'error' => $error,
        ]);

        if (!$this->isCsrfTokenValid('room_block', (string) $request->request->get('_token'))) {
            return $renderDetailsWithError([], 'flash.invalidtoken');
        }
        if (null === $input['start'] || null === $input['end']) {
            return $renderDetailsWithError([], 'roomblock.error.period');
        }

        try {
            $roomBlockService->updateBlock($block, $input['start'], $input['end'], $input['reason'], $input['note']);
        } catch (RoomBlockConflictException $e) {
            return $renderDetailsWithError($e->getConflicts(), null);
        } catch (\InvalidArgumentException $e) {
            return $renderDetailsWithError([], $e->getMessage());
        }

        return $this->renderList($request, $roomBlockRepository, $subsidiaryRepository, 'roomblock.flash.update.success', true);
    }

    #[Route('/bulk-delete', name: 'reservations.blocks.bulk_delete', methods: ['POST'])]
    /** Delete several selected blocks at once (unblock many rooms in one step). */
    public function bulkDeleteAction(
        Request $request,
        RoomBlockService $roomBlockService,
        RoomBlockRepository $roomBlockRepository,
        SubsidiaryRepository $subsidiaryRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_RESERVATIONS');

        if (!$this->isCsrfTokenValid('room_block', (string) $request->request->get('_token'))) {
            $this->addFlash('warning', 'flash.invalidtoken');

            return $this->renderList($request, $roomBlockRepository, $subsidiaryRepository);
        }

        $ids = array_values(array_filter(array_map('intval', $request->request->all('ids'))));
        $deleted = 0;
        if ([] !== $ids) {
            $deleted = $roomBlockService->deleteBlocks($roomBlockRepository->findBy(['id' => $ids]));
        }

        return $this->renderList(
            $request,
            $roomBlockRepository,
            $subsidiaryRepository,
            $deleted > 0 ? 'roomblock.flash.bulk_delete.success' : null,
            $deleted > 0,
        );
    }

    #[Route('/{id}/delete', name: 'reservations.blocks.delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    /** Delete a block (delete-popover flow). */
    public function deleteAction(
        Request $request,
        RoomBlock $block,
        RoomBlockService $roomBlockService,
        RoomBlockRepository $roomBlockRepository,
        SubsidiaryRepository $subsidiaryRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_RESERVATIONS');

        if (!$this->isCsrfTokenValid('delete'.$block->getId(), $request->request->get('_token'))) {
            $this->addFlash('warning', 'flash.invalidtoken');

            return $this->renderList($request, $roomBlockRepository, $subsidiaryRepository);
        }

        $roomBlockService->deleteBlock($block);

        return $this->renderList($request, $roomBlockRepository, $subsidiaryRepository, 'roomblock.flash.delete.success', true);
    }

    private function renderList(
        Request $request,
        RoomBlockRepository $roomBlockRepository,
        SubsidiaryRepository $subsidiaryRepository,
        ?string $successMessage = null,
        bool $blocksChanged = false,
    ): Response {
        $filter = $this->resolveListFilter($request, $subsidiaryRepository);

        return $this->render('Reservations/roomblock_list.html.twig', [
            'blocks' => $roomBlockRepository->findFiltered($filter['periodStart'], $filter['periodEnd'], $filter['subsidiary']),
            'successMessage' => $successMessage,
            // create always changes blocks; edit/delete pass the flag explicitly
            'blocksChanged' => $blocksChanged || null !== $successMessage,
            'filter' => [
                'year' => $filter['year'],
                'month' => $filter['month'],
                'subsidiaryId' => $filter['subsidiary']?->getId(),
            ],
            'years' => $this->buildYearOptions($roomBlockRepository, $filter['year']),
            'subsidiaries' => $subsidiaryRepository->findAll(),
        ]);
    }

    /**
     * Resolve year/month/subsidiary from the request into a concrete period.
     *
     * @return array{year: int, month: ?int, subsidiary: ?Subsidiary, periodStart: \DateTimeImmutable, periodEnd: \DateTimeImmutable}
     */
    private function resolveListFilter(Request $request, SubsidiaryRepository $subsidiaryRepository): array
    {
        $year = (int) ($request->query->get('year') ?: date('Y'));
        $monthRaw = (int) $request->query->get('month', 0);
        $month = ($monthRaw >= 1 && $monthRaw <= 12) ? $monthRaw : null;

        $subsidiaryId = $request->query->get('subsidiary');
        $subsidiary = ('' !== $subsidiaryId && null !== $subsidiaryId)
            ? $subsidiaryRepository->find((int) $subsidiaryId)
            : null;

        if (null !== $month) {
            $periodStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $periodEnd = $periodStart->modify('first day of next month');
        } else {
            $periodStart = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
            $periodEnd = $periodStart->modify('+1 year');
        }

        return compact('year', 'month', 'subsidiary', 'periodStart', 'periodEnd');
    }

    /**
     * Raw list-filter query params, for round-tripping the filter through edit/delete URLs.
     *
     * @return array{year: int, month: int, subsidiary: string}
     */
    private function listFilterQuery(Request $request): array
    {
        return [
            'year' => (int) ($request->query->get('year') ?: date('Y')),
            'month' => (int) $request->query->get('month', 0),
            'subsidiary' => (string) $request->query->get('subsidiary', ''),
        ];
    }

    /**
     * Build the list of selectable years (data range, always incl. current + selected year), newest first.
     *
     * @return int[]
     */
    private function buildYearOptions(RoomBlockRepository $roomBlockRepository, int $selectedYear): array
    {
        $bounds = $roomBlockRepository->findDateBounds();
        $current = (int) date('Y');
        $minYear = min($current, $selectedYear, $bounds['min'] ? (int) $bounds['min']->format('Y') : $current);
        $maxYear = max($current, $selectedYear, $bounds['max'] ? (int) $bounds['max']->format('Y') : $current);

        return range($maxYear, $minYear);
    }

    /**
     * @return array{start: ?\DateTimeImmutable, end: ?\DateTimeImmutable, reason: string, note: ?string}
     */
    private function readBlockInput(Request $request): array
    {
        $note = trim((string) $request->request->get('note', ''));

        return [
            'start' => $this->parseDate($request->request->get('from')),
            'end' => $this->parseDate($request->request->get('end')),
            'reason' => (string) $request->request->get('reason', ''),
            'note' => '' === $note ? null : $note,
        ];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param Appartment[] $rooms
     *
     * @return array<string, Appartment[]>
     */
    private function groupRoomsBySubsidiary(array $rooms): array
    {
        $grouped = [];
        foreach ($rooms as $room) {
            $grouped[$room->getObject()->getName()][] = $room;
        }
        ksort($grouped);

        return $grouped;
    }
}
