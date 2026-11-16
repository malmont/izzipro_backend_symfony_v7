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
        private readonly UrlGeneratorInterface $urls,
        private readonly ?\App\Services\TenantConnectionProvider $tenants = null,
        private readonly ?\App\Services\OtpService $otp = null,
        private readonly ?\App\Services\TenantEntityManagerProvider $emProvider = null,
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
        if (!$event->isMainRequest() || str_starts_with(\App\Services\Http\RequestPath::of($request), '/api/') || in_array($request->attributes->get('_route'), self::OPEN_ROUTES, true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$request->hasSession() || !$this->twoFactor->required($user)) {
            return;
        }
        $session = $request->getSession();
        if (\App\Security\OtpSession::validated($session, $user, $this->tenants?->getTenantCode())) { // lot SÉCURITÉ-2 : CE compte, sur CE site
            return;
        }
        $session->set('pending_otp_user', $user->getId());
        // Lot SÉCURITÉ-2 : une session sans code en attente (ouverte avant le lot, ou arrivée d'un autre site) reçoit un code, sinon
        // la page du code attendrait un courriel qui ne part pas
        try {
            $em = $this->emProvider?->getEntityManager();
            $active = $em?->getConnection()->fetchOne("SELECT COUNT(*) FROM otp_code WHERE user_otp_id = ? AND purpose = 'login' AND expiration > NOW()", [$user->getId()]);
            if ($em !== null && (int) $active === 0) {
                $this->otp?->generateAndSendOtp($user, $request);
            }
        } catch (\Throwable) {
            // un échec d'envoi n'ouvre rien : la page du code reste fermée
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('account_otp')));
    }
}
