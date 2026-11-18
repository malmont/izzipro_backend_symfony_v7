<?php

namespace App\Incident\EventSubscriber;

use App\Incident\Services\PlatformIncidentService;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Les pannes du serveur deviennent des incidents de la plateforme (lot INC-1a, 18/11/2026, `docs/incidents-et-signalements.md`) :
 * - au passage de `kernel.exception` (priorité haute, avant tout écouteur qui répond), la panne est gardée sur la requête, sans rien
 *   changer à la réponse ; depuis INC-1a (`framework.handle_all_throwables: true`), une \Error y passe aussi ;
 * - en fin de requête (`kernel.terminate`, la réponse est déjà partie), une réponse 5xx est écrite par `PlatformIncidentService::
 *   recordServerError` : la route, le site, la classe et le texte masqué de la panne ; jamais le corps ni les en-têtes. Une 4xx (429
 *   compris) n'est pas une panne.
 * Le service est reçu par une fermeture, appelée pour une réponse 5xx seulement (piège du train 102 : un abonné de fin de requête qui
 * reçoit un service le construit à chaque requête ; `Autowire(lazy: true)` en ferait une seconde instance).
 */
class ServerErrorIncidentSubscriber implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_platform_incident_error';

    /** @param \Closure(): PlatformIncidentService $incidents */
    public function __construct(#[AutowireServiceClosure(PlatformIncidentService::class)] private readonly \Closure $incidents)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 4096], KernelEvents::TERMINATE => ['onTerminate', -1024]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $event->getRequest()->attributes->set(self::ATTRIBUTE, $event->getThrowable());
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $status = $event->getResponse()->getStatusCode();
        if ($status < 500) {
            return;
        }
        $request = $event->getRequest();
        $error = $request->attributes->get(self::ATTRIBUTE);
        $route = $request->attributes->get('_route');
        if (!is_string($route) || $route === '') { // sans route connue : le chemin, identifiants remplacés
            $route = (string) preg_replace('#/\d+(?=/|$)#', '/{id}', \App\Services\Http\RequestPath::of($request));
        }
        ($this->incidents)()->recordServerError($error instanceof \Throwable ? $error : null, $status, $route, $request->headers->get('X-Request-Id'));
    }
}
