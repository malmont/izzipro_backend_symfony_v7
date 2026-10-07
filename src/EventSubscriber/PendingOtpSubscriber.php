<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\TwoFactorPolicy;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Connexion par le formulaire (EasyAdmin) d'un compte qui doit saisir le code reçu par e-mail : après le mot de
 * passe, la session est déjà authentifiée et LoginAuthenticator redirige vers /account/otp. Sans ce garde, il
 * suffisait d'ouvrir /admin directement pour se passer du code (constaté le 07/10/2026). Tant que le code n'est pas
 * validé dans la session, toute page hors de la page du code, de la connexion et de la déconnexion y renvoie.
 * L'API (/api, jetons JWT) n'est pas concernée : elle ne délivre aucun jeton avant le code.
 */
final class PendingOtpSubscriber implements EventSubscriberInterface
{
    private const OPEN_ROUTES = ['account_otp', 'app_login', 'app_logout', '_wdt', '_profiler'];

    public function __construct(
        private readonly Security $security,
        private readonly TwoFactorPolicy $twoFactor,
        private readonly UrlGeneratorInterface $urls
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // après le pare-feu (priorité 8) : l'utilisateur de la session est connu
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || str_starts_with($request->getPathInfo(), '/api/') || in_array($request->attributes->get('_route'), self::OPEN_ROUTES, true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$request->hasSession() || !$this->twoFactor->required($user)) {
            return;
        }
        $session = $request->getSession();
        if ($session->get('otp_validated')) {
            return;
        }
        $session->set('pending_otp_user', $user->getId());
        $event->setResponse(new RedirectResponse($this->urls->generate('account_otp')));
    }
}
