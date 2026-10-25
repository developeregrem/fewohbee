<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\AppSettingsService;
use App\Service\Calendar\PublicHolidayService;
use App\Twig\AppTwigExtensions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ActiveRouteNameTest extends TestCase
{
    public function testMissingRequestHasNoActiveRoute(): void
    {
        self::assertSame('', $this->extension(new RequestStack())->getActiveRouteNameFilter());
    }

    public function testRequestWithoutRouteHasNoActiveRoute(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request());

        self::assertSame('', $this->extension($requests)->getActiveRouteNameFilter());
    }

    public function testCurrentRouteReplacesUnderscoresWithDots(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(attributes: ['_route' => 'reservations_overview']));
        $requests->push(new Request(attributes: ['_route' => 'invoices_create']));

        self::assertSame('invoices.create', $this->extension($requests)->getActiveRouteNameFilter());
    }

    public function testSubrequestWithoutRouteFallsBackToMainRequest(): void
    {
        $requests = new RequestStack();
        $requests->push(new Request(attributes: ['_route' => 'reservations_overview']));
        $requests->push(new Request());

        self::assertSame('reservations.overview', $this->extension($requests)->getActiveRouteNameFilter());
    }

    private function extension(RequestStack $requests): AppTwigExtensions
    {
        return new AppTwigExtensions(
            $this->createStub(EntityManagerInterface::class),
            $requests,
            $this->createStub(PublicHolidayService::class),
            $this->createStub(AppSettingsService::class),
        );
    }
}
