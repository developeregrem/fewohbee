<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Calendar\Sync\ReservationCalendarImportService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/** Trigger a fallback hourly calendar import during app usage. */
class CalendarImportSyncSubscriber implements EventSubscriberInterface
{
    /** Configure dependencies for the calendar import fallback. */
    public function __construct(
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ReservationCalendarImportService $calendarImportService
    ) {
    }

    /** Register the subscriber on main requests. */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    /** Run a throttled import sync for authenticated users. */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->auth->isGranted('ROLE_RESERVATIONS')) {
            return;
        }

        $this->calendarImportService->syncActiveImports();
    }
}
