<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use App\Services\TenantConnectionProvider;

#[AsEventListener(event: 'kernel.request', priority: 10)]
class CsrfValidationListener
{
    public function __construct(
        private TenantConnectionProvider $tenantProvider
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!preg_match('#^/api/#', $request->getPathInfo())) {
            return;
        }

        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'])) {
            return;
        }

        // Exception vitale : Le logout doit passer pour nettoyer les cookies
        // Exception Tunnel de Paiement : Sécurisé par JWT, ne doit pas bloquer la finalisation de la commande
        $excludedPaths = [
            '/api/logout',
            '/api/stripe/create-intent',
            '/api/payment',
            '/api/order/create',
            '/api/order/create-multi-payment'
        ];

        if (in_array($request->getPathInfo(), $excludedPaths)) {
            return;
        }

        // Récupération dynamique du nom du cookie Auth selon le tenant
        $tenantCode = $this->tenantProvider->getTenantCode() ?? 'default';
        $authCookieName = 'auth_token_' . $tenantCode;

        if (!$request->cookies->has($authCookieName)) {
            // Pas de cookie d'authentification pour ce tenant => Pas de risque CSRF
            return;
        }

        // 4. Validation Double Submit Cookie
        // Le cookie XSRF-TOKEN_<tenant> (envoyé par le navigateur) doit correspondre au header X-XSRF-TOKEN (envoyé par le code JS)
        $csrfCookieName = 'XSRF-TOKEN_' . $tenantCode;
        $csrfCookie = $request->cookies->get($csrfCookieName);
        $csrfHeader = $request->headers->get('X-XSRF-TOKEN');

        if (!$csrfCookie || !$csrfHeader || $csrfCookie !== $csrfHeader) {

            $response = new JsonResponse([
                'message' => 'Invalid CSRF Token. Missing X-XSRF-TOKEN header or cookie mismatch.',
                'code' => 403
            ], 403);

            $event->setResponse($response);
        }
    }
}
