<?php

namespace App\Security\Entry;

use App\Entity\User;
use App\Services\Entry\EntryService;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * LA SESSION D'EASYADMIN QUI PORTE UNE ENTRÉE (lot AG-3c, 17/11/2026). À chaque requête de cette session, l'entrée est RELUE (ligne de la
 * base maître, site visé, échéance, lien de l'agence ou du compte de la plateforme : `EntryService::sessionEntry`) :
 * - refusée (fermée, échue, agence suspendue, site détaché, rôle retiré…) : la session est vidée, retour à la connexion du site d'attache ;
 * - la session a changé de site (`?tenant=` d'un autre) ou de compte : l'entrée est fermée (`left`) et oubliée ;
 * - la déconnexion ferme l'entrée.
 * L'opérateur d'une agence est en plus relu par `TenantUserProvider::refreshUser` (même règle) avant d'arriver ici.
 */
final class AdminEntrySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly EntryService $entries,
        private readonly TenantConnectionProvider $tenants,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // après le pare-feu (8), avant la garde du mode (7)
        return [KernelEvents::REQUEST => ['onKernelRequest', 7], LogoutEvent::class => 'onLogout'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasSession() || str_starts_with(\App\Services\Http\RequestPath::of($request), '/api/')) {
            return;
        }
        $session = $request->getSession();
        $entry = AdminEntrySession::get($session);
        $user = $this->security->getUser();
        if ($entry === null) {
            if ($user instanceof ExternalOperator) {
                // un opérateur sans entrée en session : jamais servi
                $session->invalidate();
                $event->setResponse(new RedirectResponse('/'));
            }

            return;
        }
        $current = (string) ($this->tenants->getTenantCode() ?? '');
        $same = $user instanceof ExternalOperator ? ($entry['mode'] === EntryService::MODE_AGENCY && $user->getEntryId() === $entry['id'])
            : ($user instanceof User && $entry['mode'] === EntryService::MODE_SUPER_ADMIN && $user->getId() === ($entry['account'] ?? null));
        if ($user === null || !$same || $entry['target'] !== $current) {
            // la session a quitté le site visé, ou n'est plus celle de l'entrée : l'entrée se ferme, la session continue sans elle
            $this->entries->endEntry($entry['id'], 'left');
            $session->remove(AdminEntrySession::KEY);
            if ($user instanceof ExternalOperator) {
                $session->invalidate();
                $event->setResponse(new RedirectResponse('/?tenant=' . rawurlencode($entry['home'])));
            }

            return;
        }
        $reason = $this->entries->sessionEntry($entry, $current);
        if ($reason !== null) {
            $session->invalidate();
            $event->setResponse(new RedirectResponse('/?tenant=' . rawurlencode($entry['home'])));
        }
    }

    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $entry = $request->hasSession() ? AdminEntrySession::get($request->getSession()) : null;
        if ($entry !== null) {
            $this->entries->endEntry($entry['id'], 'logout');
            $request->getSession()->remove(AdminEntrySession::KEY);
        }
    }
}
