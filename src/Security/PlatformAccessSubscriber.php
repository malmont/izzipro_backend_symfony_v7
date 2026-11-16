<?php

namespace App\Security;

use App\Entity\User;
use App\Services\Http\RequestPath;
use App\Services\Platform\PlatformAccessJournal;
use App\Services\TenantConnectionProvider;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le compte de la plateforme dans l'espace d'administration d'un site (lot AG-2, décision du propriétaire du 15/11/2026 : chaque
 * accès est tracé au journal du site, `PlatformAccessJournal`). Une session de l'espace d'administration peut passer d'un site à
 * l'autre sans nouvelle connexion (`/admin?tenant=<code>` : le compte de la plateforme existe sur chaque site). La trace ne tient
 * donc pas à la connexion seule : la PREMIÈRE requête de l'espace d'administration qu'un compte de la plateforme fait sur un site,
 * dans une session, laisse une ligne au journal de CE site. Une nouvelle connexion remet ce compte à zéro (`LoginAuthenticator`).
 *
 * Après le pare-feu (priorité 8) et après le contrôle du code à usage unique : tant que le code n'est pas validé, le compte n'est
 * pas entré, rien n'est tracé. Les routes `/api` ne passent pas ici : une connexion par l'API est tracée quand elle aboutit
 * (`AuthenticationService::recordLogin`), et son jeton est lié à un seul site.
 */
class PlatformAccessSubscriber implements EventSubscriberInterface
{
    public const SESSION_KEY = '_platform_access_traced';

    public function __construct(
        private readonly Security $security,
        private readonly TwoFactorPolicy $twoFactor,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly PlatformAccessJournal $journal,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = RequestPath::of($request);
        if (!$event->isMainRequest() || ($path !== '/admin' && !str_starts_with($path, '/admin/')) || !$request->hasSession()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !PlatformAccessJournal::isPlatformAccount($user->getRoles())) {
            return;
        }
        $session = $request->getSession();
        if ($this->twoFactor->required($user) && !$session->get('otp_validated')) {
            return;
        }
        $code = (string) ($this->tenantProvider->getTenantCode() ?? '');
        $traced = (array) $session->get(self::SESSION_KEY, []);
        if ($code === '' || in_array($code, $traced, true)) {
            return;
        }
        $this->journal->recordLogin($user, 'easyadmin');
        $session->set(self::SESSION_KEY, [...$traced, $code]);
    }
}
