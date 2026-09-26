<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Erreurs 4xx de l'API au format attendu par les fronts : { error, message } en français.
 *
 * Remplace le rendu par défaut de Symfony, qui en mode debug (APP_ENV=dev) incluait la trace complète
 * d'exécution dans le JSON. Les champs historiques title / status / detail sont conservés.
 * Les erreurs 5xx ne sont pas concernées (masquées par nginx, détail dans les logs).
 */
class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Après le pare-feu (qui transforme les refus d'accès en AccessDeniedHttpException),
        // avant le rendu d'erreur par défaut de Symfony (priorité -128)
        return [KernelEvents::EXCEPTION => ['onKernelException', -64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();

        if (!str_starts_with($request->getPathInfo(), '/api/') || !$exception instanceof HttpExceptionInterface) {
            return;
        }
        $status = $exception->getStatusCode();
        if ($status < 400 || $status >= 500) {
            return;
        }

        $message = match (true) {
            $exception instanceof AccessDeniedHttpException && in_array($exception->getMessage(), ['', 'Access Denied.'], true) => 'Accès refusé.',
            $exception instanceof NotFoundHttpException && ($exception->getMessage() === '' || str_starts_with($exception->getMessage(), 'No route found')) => 'Ressource introuvable.',
            $exception->getMessage() !== '' => $exception->getMessage(),
            default => 'Requête invalide.',
        };

        $event->setResponse(new JsonResponse([
            'error' => $message,
            'message' => $message,
            'title' => 'An error occurred',
            'status' => $status,
            'detail' => $message,
        ], $status, $exception->getHeaders()));
    }
}
