<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ReservationOrigin;
use App\Service\ReservationOriginService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ReservationOriginServiceTest extends TestCase
{
    public function testReadsAndNormalizesColorFromForm(): void
    {
        $service = new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack()
        );
        $request = new Request([], [
            'name-new' => ' Direct ',
            'color-new' => '#A1B2C3',
            'color-enabled-new' => '1',
        ]);

        $origin = $service->getOriginFromForm($request);

        self::assertSame('Direct', $origin->getName());
        self::assertSame('#a1b2c3', $origin->getColor());
    }

    public function testColorIsNullWhenIndicatorIsDisabled(): void
    {
        $service = new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack()
        );
        $request = new Request([], [
            'name-new' => 'Direct',
            'color-new' => '#a1b2c3',
        ]);

        $origin = $service->getOriginFromForm($request);

        self::assertNull($origin->getColor());
    }

    public function testInvalidEnabledColorIsNotStored(): void
    {
        $service = new ReservationOriginService(
            $this->createStub(EntityManagerInterface::class),
            new RequestStack()
        );
        $request = new Request([], [
            'name-new' => 'Direct',
            'color-new' => 'red; display: none',
            'color-enabled-new' => '1',
        ]);

        $origin = $service->getOriginFromForm($request);

        self::assertNull($origin->getColor());
    }
}
