<?php

namespace App\EventSubscriber;

use App\Services\TenantConnectionProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Limite le débit des routes publiques sensibles (connexion, OTP, mot de passe oublié, formulaires publics),
 * sans modifier leurs contrôleurs. Clés : compte visé (par tenant) et/ou adresse IP réelle du visiteur
 * (framework.trusted_proxies doit être configuré, sinon toutes les requêtes partagent l'IP du proxy).
 */
class PublicEndpointRateLimitSubscriber implements EventSubscriberInterface
{
    /** Formulaires publics limités par IP (méthode POST, chemin exact) */
    private const PUBLIC_FORMS = [
        '/api/contact/submit',
        '/api/contacts',
        '/api/contacts/create',
        '/api/newsletter/subscribe',
        '/api/candidatures',
        '/api/financement/submit',
        '/api/register',
        '/api/boussole/auth/register',
        '/api/memoires/auth/activate',
        '/api/reservations',
        '/api/form-submissions',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.api_login_account')] private readonly RateLimiterFactory $loginAccount,
        #[Autowire(service: 'limiter.api_login_ip')] private readonly RateLimiterFactory $loginIp,
        #[Autowire(service: 'limiter.api_login_global_ip')] private readonly RateLimiterFactory $loginGlobalIp,
        #[Autowire(service: 'limiter.otp_verify_account')] private readonly RateLimiterFactory $otpAccount,
        #[Autowire(service: 'limiter.password_reset_account')] private readonly RateLimiterFactory $resetAccount,
        #[Autowire(service: 'limiter.password_reset_ip')] private readonly RateLimiterFactory $resetIp,
        #[Autowire(service: 'limiter.password_reset_global_ip')] private readonly RateLimiterFactory $resetGlobalIp,
        #[Autowire(service: 'limiter.resend_verification_account')] private readonly RateLimiterFactory $resendAccount,
        #[Autowire(service: 'limiter.resend_verification_ip')] private readonly RateLimiterFactory $resendIp,
        #[Autowire(service: 'limiter.public_form_ip')] private readonly RateLimiterFactory $publicFormIp,
        #[Autowire(service: 'limiter.public_form_attempts_ip')] private readonly RateLimiterFactory $publicFormAttemptsIp,
        #[Autowire(service: 'limiter.public_form_global_ip')] private readonly RateLimiterFactory $publicFormGlobalIp,
        #[Autowire(service: 'limiter.app_activation_ip')] private readonly RateLimiterFactory $appActivationIp,
        #[Autowire(service: 'limiter.pos_pin_login')] private readonly RateLimiterFactory $posPinLogin,
        private readonly ?TenantConnectionProvider $tenant = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Avant le pare-feu (priorité 8) : un essai de connexion bloqué n'atteint pas l'authentification
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->getMethod() !== 'POST') {
            return;
        }

        $path = rtrim($request->getPathInfo(), '/');
        $ip = $request->getClientIp() ?? 'unknown';

        $checks = match (true) {
            // Connexion (01/11/2026) : par site et compte, par site et IP, plafond global par IP seule
            $path === '/api/login', $path === '/api/boussole/auth/login' => [
                [$this->loginAccount, $this->accountKey($request, ['username', 'email'])],
                [$this->loginIp, $this->siteIpKey($request)],
                [$this->loginGlobalIp, $ip],
            ],
            $path === '/api/otp-verify' => [
                [$this->otpAccount, $this->accountKey($request, ['username', 'email'])],
            ],
            // Clé d'activation d'une application mobile : essais par IP
            $path === '/api/app/activate' => [
                [$this->appActivationIp, $ip],
            ],
            // Connexion par PIN : essais par terminal (à défaut par IP)
            $path === '/api/pos/pin-login' => [
                [$this->posPinLogin, 'terminal:' . ($request->headers->get('X-Pos-Terminal') ?: $ip)],
            ],
            $path === '/api/password-reset/request', $path === '/api/password-reset/confirm' => [
                [$this->resetAccount, $this->accountKey($request, ['email'])],
                [$this->resetIp, $this->siteIpKey($request)],
                [$this->resetGlobalIp, $ip],
            ],
            // Renvoi du lien de vérification (B16, 04/11/2026) : par site et courriel, par site et IP, plafond global par IP
            // (celui du mot de passe oublié) ; avant, simple formulaire public par IP
            $path === '/api/resend-verification' => [
                [$this->resendAccount, $this->accountKey($request, ['email'])],
                [$this->resendIp, $this->siteIpKey($request)],
                [$this->resetGlobalIp, $ip],
            ],
            // envois acceptés : vérifiés ici sans être comptés (comptés à la réponse 2xx), par site puis plafond global par IP ;
            // tentatives : comptées
            in_array($path, self::PUBLIC_FORMS, true) => [
                [$this->publicFormIp, $this->publicFormKey($request), 0],
                [$this->publicFormAttemptsIp, $this->publicFormKey($request)],
                [$this->publicFormGlobalIp, $ip, 0],
            ],
            default => [],
        };

        foreach ($checks as $check) {
            [$factory, $key] = $check;
            $tokens = $check[2] ?? 1;
            if ($key === null) {
                continue;
            }
            $limit = $factory->create($key)->consume($tokens);
            if (!$limit->isAccepted() || ($tokens === 0 && $limit->getRemainingTokens() === 0)) {
                $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                $text = sprintf('Trop de tentatives. Réessayez dans %d minute(s).', (int) ceil($retryAfter / 60));
                $event->setResponse(new JsonResponse(
                    ['error' => $text, 'code' => 'RATE_LIMITED', 'message' => $text, 'retryAfterSeconds' => $retryAfter], // code, message, retryAfterSeconds : ajouts du 02/11/2026
                    JsonResponse::HTTP_TOO_MANY_REQUESTS,
                    ['Retry-After' => (string) $retryAfter]
                ));
                return;
            }
        }
    }

    /** Un envoi de formulaire public accepté (2xx) compte dans la limite des envois */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if ($event->isMainRequest() && $request->getMethod() === 'POST' && $event->getResponse()->isSuccessful()
            && in_array(rtrim($request->getPathInfo(), '/'), self::PUBLIC_FORMS, true)) {
            $this->publicFormIp->create($this->publicFormKey($request))->consume();
            $this->publicFormGlobalIp->create($request->getClientIp() ?? 'unknown')->consume();
        }
    }

    /** Formulaires publics : par site (code résolu) et IP (01/11/2026 ; avant, l'hôte du backend, commun à toutes les boutiques) */
    private function publicFormKey(Request $request): string
    {
        return $this->siteIpKey($request);
    }

    /** Site + IP : un excès sur une boutique derrière une IP partagée ne bloque pas les autres boutiques */
    private function siteIpKey(Request $request): string
    {
        return $this->siteKey($request) . '|' . ($request->getClientIp() ?? 'unknown');
    }

    /**
     * Site de la requête (01/11/2026) : code du tenant résolu par X-Tenant-Host (TenantDoctrineSwitcherListener, priorité 100,
     * passe avant), à défaut l'hôte appelé. Avant, les compteurs par compte étaient clefés par l'hôte du backend : un même
     * courriel partageait son compteur entre toutes les boutiques.
     */
    private function siteKey(Request $request): string
    {
        return $this->tenant?->getTenantCode() ?? $request->getHost();
    }

    /**
     * Compte visé par la requête (champ JSON), propre au site. null si absent : seules les limites par IP s'appliquent.
     */
    private function accountKey(Request $request, array $fields): ?string
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return null;
        }
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                return $this->siteKey($request) . '|' . mb_strtolower(trim($data[$field]));
            }
        }
        return null;
    }
}
