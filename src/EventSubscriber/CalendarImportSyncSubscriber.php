<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\CalendarImportService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Trigger a fallback hourly calendar import during app usage. */
class CalendarImportSyncSubscriber implements EventSubscriberInterface
{
    /** Configure dependencies for the calendar import fallback. */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly CalendarImportService $calendarImportService
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

        $key = CalendarImportService::buildThrottleCacheKey(time());
        $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(CalendarImportService::SYNC_THROTTLE_SECONDS);
            $this->calendarImportService->syncActiveImports();

            return true;
        });
    }
}
