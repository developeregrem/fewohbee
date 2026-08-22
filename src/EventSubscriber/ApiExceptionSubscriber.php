<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders all errors under /api/ as a consistent JSON error shape instead of HTML error pages.
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof HttpExceptionInterface) {
            $code = $throwable->getStatusCode();
            $message = $throwable->getMessage() ?: Response::$statusTexts[$code] ?? 'Error';
            $headers = $throwable->getHeaders();
        } else {
            $code = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message = 'dev' === $this->environment ? $throwable->getMessage() : 'Internal server error.';
            $headers = [];
        }

        $event->setResponse(new JsonResponse(
            ['error' => ['code' => $code, 'message' => $message]],
            $code,
            $headers
        ));
    }
}
