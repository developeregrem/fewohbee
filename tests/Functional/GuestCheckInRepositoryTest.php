<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\GuestCheckInRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GuestCheckInRepositoryTest extends KernelTestCase
{
    /** @var list<int> */
    private array $reservationIds = [];

    public function testRequestingAnExistingLinkAgainUsesNoNewId(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(GuestCheckInRepository::class);
        $this->reservationIds = array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT id FROM reservations WHERE id NOT IN (SELECT reservation_id FROM guest_check_in) ORDER BY id LIMIT 2'
        ));
        self::assertCount(2, $this->reservationIds, 'The sample data contains reservations.');
        [$first, $second] = $this->reservationIds;
        $now = new \DateTimeImmutable();

        $selector = $repository->ensureSelector($first, 'firstCandidate00000000', $now);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame($selector, $repository->ensureSelector($first, 'otherCandidate'.$i.'0000000', $now));
        }
        $repository->ensureSelector($second, 'secondCandidate0000000', $now);

        $firstId = (int) $this->connection()->fetchOne('SELECT id FROM guest_check_in WHERE reservation_id = ?', [$first]);
        $secondId = (int) $this->connection()->fetchOne('SELECT id FROM guest_check_in WHERE reservation_id = ?', [$second]);
        self::assertSame($firstId + 1, $secondId, 'Repeated requests for an existing link must not leave gaps in the ids.');
    }

    protected function tearDown(): void
    {
        if ([] !== $this->reservationIds) {
            $this->connection()->executeStatement('DELETE FROM guest_check_in WHERE reservation_id IN (?, ?)', $this->reservationIds);
        }
        parent::tearDown();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
