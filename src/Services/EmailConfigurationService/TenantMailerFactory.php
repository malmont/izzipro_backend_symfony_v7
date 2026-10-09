<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Services\TenantConnectionProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * Mailers des sites. Depuis le 09/10/2026, les courriels partent par le worker « email » (QueuedMailer) : composés dans
 * la requête, envoyés en tâche de fond par le serveur SMTP du site (configuration d'envoi complète) ou de la
 * plateforme. Les identifiants SMTP ne passent jamais par la file : le worker relit la configuration dans la base du
 * site. createDirectMailer garde l'envoi immédiat (commande de test SMTP).
 */
class TenantMailerFactory
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly BodyRendererInterface $renderer,
        private readonly TenantEmailDelivery $delivery,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Mailer du site courant : serveur du site si sa configuration d'envoi est complète, sinon celui de la plateforme */
    public function createMailer(?EmailConfiguration $config): MailerInterface
    {
        if (!TenantEmailDelivery::isComplete($config)) {
            $this->logger->warning('[TenantMailerFactory] EmailConfiguration incomplète pour SMTP dédié, repli sur le serveur de la plateforme.');

            return $this->createPlatformMailer();
        }

        return new QueuedMailer($this->bus, $this->renderer, $this->delivery, $this->logger,
            $this->tenantProvider->getTenantCode(), $this->currentDb(), $config->getId());
    }

    /** Mailer du serveur de la plateforme (MAILER_DSN), par la file */
    public function createPlatformMailer(): MailerInterface
    {
        return new QueuedMailer($this->bus, $this->renderer, $this->delivery, $this->logger, $this->tenantProvider->getTenantCode(), $this->currentDb(), null);
    }

    /** Envoi immédiat, sans file (commande de test SMTP : l'erreur du serveur doit s'afficher tout de suite) */
    public function createDirectMailer(?EmailConfiguration $config): MailerInterface
    {
        return new Mailer($this->delivery->transportOrDefault($config));
    }

    private function currentDb(): ?string
    {
        try {
            $db = $this->tenantProvider->getConnection()->getParams()['dbname'] ?? null;

            return is_string($db) ? $db : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
