<?php

namespace App\EventSubscriber;

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
        '/api/resend-verification',
        '/api/boussole/auth/register',
        '/api/memoires/auth/activate',
        '/api/reservations',
    ];

    public function __construct(
        #[Autowire(service: 'limiter.api_login_account')] private readonly RateLimiterFactory $loginAccount,
        #[Autowire(service: 'limiter.api_login_ip')] private readonly RateLimiterFactory $loginIp,
        #[Autowire(service: 'limiter.otp_verify_account')] private readonly RateLimiterFactory $otpAccount,
        #[Autowire(service: 'limiter.password_reset_account')] private readonly RateLimiterFactory $resetAccount,
        #[Autowire(service: 'limiter.password_reset_ip')] private readonly RateLimiterFactory $resetIp,
        #[Autowire(service: 'limiter.public_form_ip')] private readonly RateLimiterFactory $publicFormIp,
        #[Autowire(service: 'limiter.public_form_attempts_ip')] private readonly RateLimiterFactory $publicFormAttemptsIp,
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
            $path === '/api/login', $path === '/api/boussole/auth/login' => [
                [$this->loginAccount, $this->accountKey($request, ['username', 'email'])],
                [$this->loginIp, $ip],
            ],
            $path === '/api/otp-verify' => [
                [$this->otpAccount, $this->accountKey($request, ['username', 'email'])],
            ],
            $path === '/api/password-reset/request' => [
                [$this->resetAccount, $this->accountKey($request, ['email'])],
                [$this->resetIp, $ip],
            ],
            // envois acceptés : vérifiés ici sans être comptés (comptés à la réponse 2xx) ; tentatives : comptées
            in_array($path, self::PUBLIC_FORMS, true) => [
                [$this->publicFormIp, $this->publicFormKey($request), 0],
                [$this->publicFormAttemptsIp, $this->publicFormKey($request)],
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
                $event->setResponse(new JsonResponse(
                    ['error' => sprintf('Trop de tentatives. Réessayez dans %d minute(s).', (int) ceil($retryAfter / 60))],
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
        }
    }

    private function publicFormKey(Request $request): string
    {
        return $request->getHost() . '|' . ($request->getClientIp() ?? 'unknown');
    }

    /**
     * Compte visé par la requête (champ JSON), propre au tenant. null si absent : seules les limites par IP s'appliquent.
     */
    private function accountKey(Request $request, array $fields): ?string
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return null;
        }
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                return $request->getHost() . '|' . mb_strtolower(trim($data[$field]));
            }
        }
        return null;
    }
}
