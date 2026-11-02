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
 * Depuis le 02/11/2026 : clé `code` stable (MAJUSCULES_SOULIGNÉES) ajoutée, pour que la caisse traduise sans lire le texte :
 * code de l'exception quand elle en porte un (CartQuoteException enveloppée : QUOTE_INVALID, VARIANT_NOT_FOUND, CARRIER_NOT_FOUND,
 * avec `errors[{path, message}]` du devis), sinon un code par statut (BAD_REQUEST, UNAUTHENTICATED, FORBIDDEN, NOT_FOUND,
 * METHOD_NOT_ALLOWED, CONFLICT, VALIDATION_FAILED, RATE_LIMITED). `error`, `message`, `title`, `status`, `detail` inchangés.
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
            'code' => self::code($exception, $status),
            'message' => $message,
            'title' => 'An error occurred',
            'status' => $status,
            'detail' => $message,
        ] + self::details($exception), $status, $exception->getHeaders()));
    }

    /** Code stable de l'erreur : celui de l'exception enveloppée (devis), sinon par statut */
    public static function code(\Throwable $exception, int $status): string
    {
        $quote = self::quoteException($exception);
        if ($quote !== null) {
            return match ($quote->getStatusCode()) {
                404 => str_contains($quote->getMessage(), 'carrierId') ? 'CARRIER_NOT_FOUND' : 'VARIANT_NOT_FOUND',
                default => 'QUOTE_INVALID',
            };
        }

        return match ($status) {
            400 => 'BAD_REQUEST', 401 => 'UNAUTHENTICATED', 402 => 'PAYMENT_REQUIRED', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT', 410 => 'GONE', 413 => 'PAYLOAD_TOO_LARGE', 415 => 'UNSUPPORTED_MEDIA_TYPE', 422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED',
            default => 'HTTP_' . $status,
        };
    }

    /** @return array{errors?: list<array{path: string, message: string}>} détail champ par champ d'un devis refusé (ajout) */
    public static function quoteErrors(\Throwable $exception): array
    {
        return self::details($exception);
    }

    /** @return array{errors?: list<array{path: string, message: string}>} */
    private static function details(\Throwable $exception): array
    {
        $quote = self::quoteException($exception);

        return $quote !== null && $quote->errors !== [] ? ['errors' => $quote->errors] : [];
    }

    private static function quoteException(\Throwable $exception): ?\App\Services\OrderService\CartQuoteException
    {
        for ($e = $exception; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof \App\Services\OrderService\CartQuoteException) {
                return $e;
            }
        }

        return null;
    }
}
