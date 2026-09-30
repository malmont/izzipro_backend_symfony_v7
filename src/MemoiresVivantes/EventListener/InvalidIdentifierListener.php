<?php

namespace App\MemoiresVivantes\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Les contrôleurs du module lisent les identifiants avec Uuid::fromString() : un identifiant mal formé levait une
 * exception non interceptée (erreur 500). C'est une ressource introuvable.
 */
#[AsEventListener(event: 'kernel.exception', priority: 16)]
class InvalidIdentifierListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof \InvalidArgumentException
            || !str_starts_with($exception->getMessage(), 'Invalid UUID')
            || !str_starts_with($event->getRequest()->getPathInfo(), '/api/memoires/')) {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => 'Identifiant invalide.'], Response::HTTP_NOT_FOUND));
    }
}
