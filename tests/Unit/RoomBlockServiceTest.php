<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Enum\RoomBlockSource;
use App\Entity\Reservation;
use App\Entity\RoomBlock;
use App\Exception\RoomBlockConflictException;
use App\Service\AvailabilityService;
use App\Service\RoomBlockService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class RoomBlockServiceTest extends TestCase
{
    public function testCreateBlocksForMultipleRooms(): void
    {
        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('getConflictingReservations')->willReturn([]);
        $availability->method('getConflictingBlocks')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::once())->method('flush');

        $service = new RoomBlockService($em, $availability, $this->createStub(Security::class));
        $blocks = $service->createBlocks(
            [self::makeRoom(1), self::makeRoom(2)],
            new \DateTimeImmutable('2026-08-01'),
            new \DateTimeImmutable('2026-08-05'),
            '  Renovierung  ',
            'Bad wird saniert'
        );

        self::assertCount(2, $blocks);
        self::assertSame('Renovierung', $blocks[0]->getReason());
        self::assertSame('Bad wird saniert', $blocks[0]->getNote());
        self::assertSame(RoomBlockSource::MANUAL, $blocks[0]->getSource());
        self::assertNotNull($blocks[0]->getUuid());
        self::assertSame(4, $blocks[0]->getNights());
    }

    public function testCreateBlocksIsAllOrNothingOnConflict(): void
    {
        $roomA = self::makeRoom(1);
        $roomB = self::makeRoom(2);
        $conflicting = new Reservation();

        $availability = $this->createStub(AvailabilityService::class);
        // room B has a conflicting reservation, room A is free
        $availability->method('getConflictingReservations')->willReturnCallback(
            static fn (Appartment $room): array => 2 === $room->getId() ? [$conflicting] : []
        );
        $availability->method('getConflictingBlocks')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $service = new RoomBlockService($em, $availability, $this->createStub(Security::class));

        try {
            $service->createBlocks([$roomA, $roomB], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'), 'Renovierung');
            self::fail('Expected RoomBlockConflictException');
        } catch (RoomBlockConflictException $e) {
            self::assertArrayHasKey(2, $e->getConflicts());
            self::assertArrayNotHasKey(1, $e->getConflicts());
            self::assertSame([$conflicting], $e->getConflicts()[2]['reservations']);
        }
    }

    public function testCreateBlocksSwapsReversedDates(): void
    {
        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('getConflictingReservations')->willReturn([]);
        $availability->method('getConflictingBlocks')->willReturn([]);

        $service = new RoomBlockService($this->createStub(EntityManagerInterface::class), $availability, $this->createStub(Security::class));
        $blocks = $service->createBlocks([self::makeRoom(1)], new \DateTimeImmutable('2026-08-05'), new \DateTimeImmutable('2026-08-01'), 'Renovierung');

        self::assertSame('2026-08-01', $blocks[0]->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-08-05', $blocks[0]->getEndDate()->format('Y-m-d'));
    }

    public function testCreateBlocksRejectsZeroNights(): void
    {
        $service = new RoomBlockService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AvailabilityService::class),
            $this->createStub(Security::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->createBlocks([self::makeRoom(1)], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-01'), 'Renovierung');
    }

    public function testCreateBlocksRejectsEmptyReason(): void
    {
        $service = new RoomBlockService(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AvailabilityService::class),
            $this->createStub(Security::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->createBlocks([self::makeRoom(1)], new \DateTimeImmutable('2026-08-01'), new \DateTimeImmutable('2026-08-05'), '   ');
    }

    public function testUpdateBlockIgnoresItself(): void
    {
        $room = self::makeRoom(1);
        $block = (new RoomBlock())->setAppartment($room)
            ->setStartDate(new \DateTimeImmutable('2026-08-01'))
            ->setEndDate(new \DateTimeImmutable('2026-08-05'))
            ->setReason('Renovierung');

        $availability = $this->createMock(AvailabilityService::class);
        $availability->method('getConflictingReservations')->willReturn([]);
        $availability->expects(self::once())->method('getConflictingBlocks')
            ->with($room, self::anything(), self::anything(), $block)
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $service = new RoomBlockService($em, $availability, $this->createStub(Security::class));
        $service->updateBlock($block, new \DateTimeImmutable('2026-08-02'), new \DateTimeImmutable('2026-08-06'), 'Wasserschaden', null);

        self::assertSame('2026-08-02', $block->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-08-06', $block->getEndDate()->format('Y-m-d'));
        self::assertSame('Wasserschaden', $block->getReason());
        self::assertNull($block->getNote());
    }

    public function testUpdateBlockThrowsOnConflict(): void
    {
        $room = self::makeRoom(1);
        $block = (new RoomBlock())->setAppartment($room)
            ->setStartDate(new \DateTimeImmutable('2026-08-01'))
            ->setEndDate(new \DateTimeImmutable('2026-08-05'))
            ->setReason('Renovierung');

        $availability = $this->createStub(AvailabilityService::class);
        $availability->method('getConflictingReservations')->willReturn([new Reservation()]);
        $availability->method('getConflictingBlocks')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $service = new RoomBlockService($em, $availability, $this->createStub(Security::class));

        $this->expectException(RoomBlockConflictException::class);
        $service->updateBlock($block, new \DateTimeImmutable('2026-08-02'), new \DateTimeImmutable('2026-08-06'), 'Wasserschaden');
    }

    public function testDeleteBlockRemovesAndFlushes(): void
    {
        $block = (new RoomBlock())->setAppartment(self::makeRoom(1))
            ->setStartDate(new \DateTimeImmutable('2026-08-01'))
            ->setEndDate(new \DateTimeImmutable('2026-08-05'))
            ->setReason('Renovierung');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($block);
        $em->expects(self::once())->method('flush');

        $service = new RoomBlockService($em, $this->createStub(AvailabilityService::class), $this->createStub(Security::class));
        $service->deleteBlock($block);
    }

    private static function makeRoom(int $id): Appartment
    {
        $room = new Appartment();
        $room->setId($id);
        $room->setNumber((string) $id);
        $room->setBedsMax(2);

        return $room;
    }
}
