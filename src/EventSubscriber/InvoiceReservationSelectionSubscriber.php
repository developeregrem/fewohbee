<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\InvoiceReservationSelectionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/** Reports stale invoice selections without discarding the draft or exposing technical errors. */
final class InvoiceReservationSelectionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof InvoiceReservationSelectionException) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('feedback.html.twig', [
                'feedbackMessages' => ['warning' => [$exception->getMessage()]],
            ]),
            Response::HTTP_CONFLICT,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        ));
    }
}
