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

namespace App\Service;

use App\Entity\Appartment;
use App\Entity\Enum\RoomBlockSource;
use App\Entity\RoomBlock;
use App\Entity\User;
use App\Exception\RoomBlockConflictException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Application service for room block mutations; future emission point for channel-sync events.
 */
class RoomBlockService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvailabilityService $availabilityService,
        private readonly Security $security,
    ) {
    }

    /**
     * Creates one block per room, all-or-nothing: every room is validated first;
     * any conflict aborts the whole batch without persisting.
     *
     * @param Appartment[] $rooms
     *
     * @return RoomBlock[]
     *
     * @throws RoomBlockConflictException
     * @throws \InvalidArgumentException for an empty period or missing reason
     */
    public function createBlocks(
        array $rooms,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $reason,
        ?string $note = null,
        RoomBlockSource $source = RoomBlockSource::MANUAL,
    ): array {
        [$start, $end, $reason] = $this->normalizeInput($start, $end, $reason);

        $conflicts = [];
        foreach ($rooms as $room) {
            $conflictingReservations = $this->availabilityService->getConflictingReservations($room, $start, $end);
            $conflictingBlocks = $this->availabilityService->getConflictingBlocks($room, $start, $end);
            if (count($conflictingReservations) > 0 || count($conflictingBlocks) > 0) {
                $conflicts[$room->getId()] = [
                    'room' => $room,
                    'reservations' => $conflictingReservations,
                    'blocks' => $conflictingBlocks,
                ];
            }
        }
        if (count($conflicts) > 0) {
            throw new RoomBlockConflictException($conflicts);
        }

        $user = $this->security->getUser();
        $blocks = [];
        foreach ($rooms as $room) {
            $block = new RoomBlock();
            $block->setAppartment($room)
                ->setStartDate($start)
                ->setEndDate($end)
                ->setReason($reason)
                ->setNote($note)
                ->setSource($source)
                ->setCreatedBy($user instanceof User ? $user : null);
            $this->em->persist($block);
            $blocks[] = $block;
        }
        $this->em->flush();

        return $blocks;
    }

    /**
     * Updates period/reason/note; the room cannot be changed (delete and recreate instead).
     *
     * @throws RoomBlockConflictException
     */
    public function updateBlock(RoomBlock $block, \DateTimeImmutable $start, \DateTimeImmutable $end, string $reason, ?string $note = null): void
    {
        [$start, $end, $reason] = $this->normalizeInput($start, $end, $reason);

        $room = $block->getAppartment();
        $conflictingReservations = $this->availabilityService->getConflictingReservations($room, $start, $end);
        $conflictingBlocks = $this->availabilityService->getConflictingBlocks($room, $start, $end, $block);
        if (count($conflictingReservations) > 0 || count($conflictingBlocks) > 0) {
            throw new RoomBlockConflictException([
                $room->getId() => [
                    'room' => $room,
                    'reservations' => $conflictingReservations,
                    'blocks' => $conflictingBlocks,
                ],
            ]);
        }

        $block->setStartDate($start)
            ->setEndDate($end)
            ->setReason($reason)
            ->setNote($note);
        $this->em->flush();
    }

    public function deleteBlock(RoomBlock $block): void
    {
        $this->em->remove($block);
        $this->em->flush();
    }

    /**
     * Delete several blocks in one transaction.
     *
     * @param RoomBlock[] $blocks
     *
     * @return int number of deleted blocks
     */
    public function deleteBlocks(array $blocks): int
    {
        foreach ($blocks as $block) {
            $this->em->remove($block);
        }
        $this->em->flush();

        return count($blocks);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}
     */
    private function normalizeInput(\DateTimeImmutable $start, \DateTimeImmutable $end, string $reason): array
    {
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        if ($start == $end) {
            throw new \InvalidArgumentException('roomblock.error.period');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('roomblock.error.reason');
        }

        return [$start, $end, $reason];
    }
}
