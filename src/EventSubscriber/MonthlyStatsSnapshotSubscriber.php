<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\MonthlyStatsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class MonthlyStatsSnapshotSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly MonthlyStatsService $monthlyStatsService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if (!str_starts_with($route, 'statistics')) {
            return;
        }

        if (!$this->auth->isGranted('ROLE_STATISTICS')) {
            return;
        }

        $key = sprintf(
            'stats_snapshot_check_%s_%s',
            $request->getLocale(),
            (new \DateTimeImmutable('now'))->format('Y-m-d')
        );
        $this->cache->get($key, function (ItemInterface $item) {
            $item->expiresAfter(43200);
            $this->monthlyStatsService->runSnapshotMaintenance();

            return true;
        });
    }
}
