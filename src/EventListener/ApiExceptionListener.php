<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Les routes /api/* renvoient toujours une erreur JSON, sans trace ni chemin serveur
 * (sauf en debug). Priorité négative : le pare-feu (401/403 JWT) passe avant.
 */
#[AsEventListener(event: 'kernel.exception', priority: -64)]
class ApiExceptionListener
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->debug || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $event->setResponse(new JsonResponse(
            ['error' => Response::$statusTexts[$status] ?? 'Error'],
            $status,
            $headers
        ));
    }
}
