<?php
// src/EventListener/JWTDecodedListener.php

namespace App\EventListener;

use App\Services\TenantConnectionProvider;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Psr\Log\LoggerInterface;

class JWTDecodedListener
{
    public function __construct(
        private TenantConnectionProvider $tenantProvider,
        private LoggerInterface $logger
    ) {}

    public function onJWTDecoded(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();

        // 1. Tenant du token
        $tokenTenant = $payload['tenant_code'] ?? null;

        // 2. Tenant actuel identifié par le switcher
        $currentTenant = $this->tenantProvider->getTenantCode();

        // 3. Si les deux sont définis et différents -> violation d'isolation
        if ($tokenTenant && $currentTenant && $tokenTenant !== $currentTenant) {
            $this->logger->warning(
                "JWT Isolation Breach: Token for '$tokenTenant' used on '$currentTenant'. Rejecting."
            );
            $event->markAsInvalid();
        }
        // NOTE: Si $tokenTenant est null (vieux token sans claim), on le laisse passer
        // pour ne pas bloquer les utilisateurs pendant la transition.
    }
}
