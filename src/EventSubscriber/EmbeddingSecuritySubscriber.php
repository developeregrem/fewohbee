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

namespace App\EventSubscriber;

use App\Service\OnlineBookingConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sets Content-Security-Policy frame-ancestors for embeddable routes (/book)
 * and removes the conflicting X-Frame-Options header for those routes.
 */
class EmbeddingSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OnlineBookingConfigService $onlineBookingConfigService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/book')) {
            return;
        }

        $response = $event->getResponse();

        // Remove X-Frame-Options — it conflicts with frame-ancestors and does not support multiple origins
        $response->headers->remove('X-Frame-Options');

        $ancestors = "'self'";
        $allowedOrigins = $this->onlineBookingConfigService->getAllowedEmbeddingOriginsForCsp();
        if ('' !== $allowedOrigins) {
            $ancestors .= ' ' . $allowedOrigins;
        }

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors}");
    }
}
