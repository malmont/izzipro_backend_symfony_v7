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
 * Hors debug (production), c'est ce gestionnaire qui répond, avant ApiExceptionSubscriber (même priorité) : `{error: texte du statut}`
 * en anglais (« Forbidden », « Not Found », « Bad Request »). Depuis le 02/11/2026, les 4xx portent aussi `code` (stable, même règle que
 * ApiExceptionSubscriber::code : par statut, ou VARIANT_NOT_FOUND / CARRIER_NOT_FOUND / QUOTE_INVALID + errors[] pour un devis refusé)
 * et `message` (= le même texte) ; `error` et le statut ne changent pas.
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

        $text = Response::$statusTexts[$status] ?? 'Error';
        $body = ['error' => $text];
        if ($status >= 400 && $status < 500) { // code stable et message (ajouts du 02/11/2026) ; 5xx inchangées
            $body += ['code' => \App\EventSubscriber\ApiExceptionSubscriber::code($exception, $status), 'message' => $text] + \App\EventSubscriber\ApiExceptionSubscriber::quoteErrors($exception);
        }
        $event->setResponse(new JsonResponse($body, $status, $headers));
    }
}
