<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Services\PosAuditService\PosIdempotencyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Idempotence des écritures de caisse (chantier C5) : sur les routes concernées, l'en-tête Idempotency-Key est traité
 * après l'authentification (rejeu, 409, 422, ou réservation), et la clé est close à la réponse. Sans en-tête, cet
 * abonné ne fait rien : les autres clients (boutique web, EasyAdmin) ne sont pas concernés.
 */
class PosIdempotencySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PosIdempotencyService $idempotency, private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0], // après le pare-feu (8) : l'utilisateur est connu
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !in_array($request->attributes->get('_route'), PosIdempotencyService::ROUTES, true) || !$request->headers->has(PosIdempotencyService::HEADER)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // non connecté : le contrôleur répondra 401 comme d'habitude
        }
        $response = $this->idempotency->begin($request, $user);
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->idempotency->isActive()) {
            $this->idempotency->complete($event->getResponse());
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if ($this->idempotency->isActive()) {
            $this->idempotency->complete(null);
        }
    }
}
