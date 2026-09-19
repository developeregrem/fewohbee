<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\InvalidReservationPeriodException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns invalid reservation periods into safe, translated HTTP 422 responses.
 */
final class InvalidReservationPeriodSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 10]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof InvalidReservationPeriodException) {
            return;
        }

        $message = $this->translator->trans($exception->getMessage());
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'code' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'message' => $message,
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY));

            return;
        }

        $event->setResponse(new Response(
            $message,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        ));
    }
}
