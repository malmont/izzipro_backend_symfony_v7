<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpFoundation\Request;
use App\Services\TenantConnectionProvider;

class JWTFromCookieListener
{
    public function __construct(
        private TenantConnectionProvider $tenantProvider
    ) {}

    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();

        // Isolation par Tenant : Le nom du cookie dépend du tenant courant
        $tenantCode = $this->tenantProvider->getTenantCode() ?? 'default';
        $cookieName = 'auth_token_' . $tenantCode;

        if (!$request->headers->has('Authorization') && $request->cookies->has($cookieName)) {
            $jwt = $request->cookies->get($cookieName);

            $request->headers->set('Authorization', sprintf('Bearer %s', $jwt));
        }
    }
}
