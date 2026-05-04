<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\BankImportEditException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Translates {@see BankImportEditException} (thrown by the bank-import draft
 * resolver and the edit endpoints) into the JSON shape the JS frontend
 * expects: HTTP status from the exception, body `{"error": "<code>"}`.
 */
final class BankImportEditExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onException',
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof BankImportEditException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => $exception->errorCode],
            $exception->httpStatus,
        ));
    }
}
