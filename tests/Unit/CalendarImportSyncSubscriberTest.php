<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\CalendarImportSyncSubscriber;
use App\Service\Calendar\Sync\ReservationCalendarImportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Verify that eligible requests delegate fallback synchronization to the service. */
final class CalendarImportSyncSubscriberTest extends TestCase
{
    public function testAuthorizedMainRequestDelegatesThrottleHandlingToService(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::once())->method('isGranted')->with('ROLE_RESERVATIONS')->willReturn(true);
        $service = $this->createMock(ReservationCalendarImportService::class);
        $service->expects(self::once())->method('syncActiveImports')->with(false);

        $subscriber = new CalendarImportSyncSubscriber($auth, $service);

        $subscriber->onKernelRequest($this->createRequestEvent(HttpKernelInterface::MAIN_REQUEST));
    }

    public function testUnauthorizedRequestDoesNotStartFallbackSync(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::once())->method('isGranted')->with('ROLE_RESERVATIONS')->willReturn(false);
        $service = $this->createMock(ReservationCalendarImportService::class);
        $service->expects(self::never())->method('syncActiveImports');

        $subscriber = new CalendarImportSyncSubscriber($auth, $service);

        $subscriber->onKernelRequest($this->createRequestEvent(HttpKernelInterface::MAIN_REQUEST));
    }

    public function testSubRequestDoesNotStartFallbackSync(): void
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->expects(self::never())->method('isGranted');
        $service = $this->createMock(ReservationCalendarImportService::class);
        $service->expects(self::never())->method('syncActiveImports');

        $subscriber = new CalendarImportSyncSubscriber($auth, $service);

        $subscriber->onKernelRequest($this->createRequestEvent(HttpKernelInterface::SUB_REQUEST));
    }

    /** Create a kernel request event with the requested main/sub-request type. */
    private function createRequestEvent(int $requestType): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/'),
            $requestType,
        );
    }
}
