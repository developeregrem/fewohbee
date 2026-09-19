<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ReservationOrigin;
use App\Service\PriceService;
use App\Service\ReservationPeriodService;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a price counts as brokered by the portal.
 *
 * The switch is put for miscellaneous prices only. A night is the thing a
 * portal brokers, so the form leaves the question out there - and a field that
 * was never shown must not be read as an answer.
 */
final class PriceServiceBrokeredTest extends TestCase
{
    public function testAMiscPriceTakesTheAnswerFromTheForm(): void
    {
        self::assertTrue($this->parse(type: 1, brokered: '1')->isBrokered());
        self::assertFalse($this->parse(type: 1, brokered: null)->isBrokered(), 'the switch was turned off');
    }

    public function testAnApartmentPriceStaysBrokeredWithoutBeingAsked(): void
    {
        // The form hides and disables the switch for apartment prices, so
        // nothing is posted. Reading that as "not brokered" would record the
        // opposite of what the calculator does with the night.
        self::assertTrue($this->parse(type: 2, brokered: null)->isBrokered());
    }

    private function parse(int $type, ?string $brokered): \App\Entity\Price
    {
        $params = [
            'description-new' => 'Frühstück',
            'price-new' => '12.00',
            'vat-new' => '7',
            'type-new' => (string) $type,
        ];
        if (null !== $brokered) {
            $params['brokered-new'] = $brokered;
        }

        // The service also resolves origins and categories against repositories.
        // findById() is one of Doctrine's magic finders and cannot be stubbed,
        // so this stands in and answers everything with nothing.
        $repository = new class($this->createStub(EntityManagerInterface::class), new ClassMetadata(ReservationOrigin::class)) extends EntityRepository {
            /** @return array<int, object> */
            public function findById(mixed $ids): array
            {
                return [];
            }

            public function find(mixed $id, mixed $lockMode = null, mixed $lockVersion = null): ?object
            {
                return null;
            }

            /** @return array<int, object> */
            public function findAll(): array
            {
                return [];
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $service = new PriceService($em, new ReservationPeriodService());

        return $service->getPriceFromForm(new Request([], $params), 'new');
    }
}
