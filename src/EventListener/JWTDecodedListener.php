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

        // 3. Le jeton d'un site ne vaut que sur ce site
        if ($tokenTenant && $currentTenant && $tokenTenant !== $currentTenant) {
            $this->logger->warning(
                "JWT Isolation Breach: Token for '$tokenTenant' used on '$currentTenant'. Rejecting."
            );
            $event->markAsInvalid();

            return;
        }

        // 4. Jeton sans tenant_code (émis hors de tout site) présenté sur un site : refusé depuis le 30/09/2026.
        //    Il reste valable là où il a été émis (requête sans site reconnu), comme avant.
        if (!$tokenTenant && $currentTenant) {
            $this->logger->warning("JWT sans tenant_code présenté sur '$currentTenant' : refusé.");
            $event->markAsInvalid();

            return;
        }

        // 5. Jeton d'un site présenté hors de tout site : accepté comme avant, journalisé pour décider d'un refus
        if ($tokenTenant && !$currentTenant) {
            $this->logger->notice("JWT de '$tokenTenant' présenté sans site reconnu.");
        }
    }
}
