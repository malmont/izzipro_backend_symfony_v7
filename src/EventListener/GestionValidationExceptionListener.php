<?php

namespace App\EventListener;

use App\Services\GestionService\GestionConflictException;
use App\Services\GestionService\GestionValidationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Erreurs applicatives des routes de gestion, en débogage comme en production (priorité positive : avant
 * ApiExceptionSubscriber et ApiExceptionListener) : entrée refusée (lot G1) → 422 `{error: VALIDATION_FAILED, code, message,
 * fields}` ; conflit d'état (lot G2) → 409 `{error: CODE, code, message}`.
 */
#[AsEventListener(event: 'kernel.exception', priority: 16)]
class GestionValidationExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        if ($exception instanceof GestionValidationException) {
            $event->setResponse(new JsonResponse($exception->toArray(), 422));
        } elseif ($exception instanceof GestionConflictException) {
            $event->setResponse(new JsonResponse($exception->toArray(), $exception->status));
        }
    }
}
