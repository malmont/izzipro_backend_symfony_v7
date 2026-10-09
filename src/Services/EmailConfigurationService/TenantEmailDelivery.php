<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Message\SendTenantEmailMessage;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Envoi effectif d'un courriel, par le serveur SMTP du site (EmailConfiguration complète) ou, à défaut, par celui de la
 * plateforme (MAILER_DSN). Appelé par le worker « email » (SendTenantEmailHandler) et, en secours, directement quand la
 * file est injoignable. Une erreur SMTP remonte : Messenger relance le message.
 */
final class TenantEmailDelivery
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $connectionProvider,
        private readonly TransportInterface $defaultTransport,
        private readonly LoggerInterface $logger
    ) {
    }

    public function deliver(SendTenantEmailMessage $message): void
    {
        $this->deliverRaw($message->email, $message->envelope, $message->tenantCode, $message->tenantDb, $message->configId);
    }

    /** Envoi par le serveur du site (configId relu dans sa base) ou de la plateforme (configId null) */
    public function deliverRaw(RawMessage $email, ?Envelope $envelope, ?string $tenantCode, ?string $tenantDb, ?int $configId): void
    {
        $transport = null;
        if ($configId !== null && $tenantDb !== null) {
            // Bascule seulement si besoin : switchTenant vide l'EntityManager (envoi immédiat au milieu d'une requête)
            if (($this->connectionProvider->getConnection()->getParams()['dbname'] ?? null) !== $tenantDb) {
                $this->emProvider->switchTenant($tenantDb, $tenantCode);
            }
            $config = $this->emProvider->getEntityManager()->getRepository(EmailConfiguration::class)->find($configId);
            $transport = $this->transportFor($config);
            if ($transport === null) {
                $this->logger->warning('[Email] Configuration d\'envoi du site absente ou incomplète : serveur de la plateforme utilisé', ['site' => $tenantCode]);
            }
        }
        $this->send($transport ?? $this->defaultTransport, $email, $envelope);
    }

    /** Transport du site, sinon celui de la plateforme : envoi immédiat (commande de test SMTP) */
    public function transportOrDefault(?EmailConfiguration $config): TransportInterface
    {
        return $this->transportFor($config) ?? $this->defaultTransport;
    }

    /** Une configuration d'envoi est utilisable si elle a serveur, utilisateur et mot de passe */
    public static function isComplete(?EmailConfiguration $config): bool
    {
        return $config !== null && $config->getSmtpHost() && $config->getSmtpUser() && $config->getSmtpPassword();
    }

    /** Transport SMTP du site, ou null (configuration incomplète ou invalide) */
    public function transportFor(?EmailConfiguration $config): ?TransportInterface
    {
        if (!self::isComplete($config)) {
            return null;
        }
        try {
            $port = (int) ($config->getSmtpPort() ?: 465);
            $encryption = strtolower((string) ($config->getSmtpEncryption() ?: 'ssl'));
            $scheme = ($port === 465 || $encryption === 'ssl') ? 'smtps' : 'smtp';

            return Transport::fromDsn(sprintf('%s://%s:%s@%s:%d', $scheme, urlencode((string) $config->getSmtpUser()), urlencode((string) $config->getSmtpPassword()), $config->getSmtpHost(), $port));
        } catch (\Throwable $e) {
            $this->logger->error('[Email] Transport SMTP du site invalide : ' . $e->getMessage());

            return null;
        }
    }

    private function send(TransportInterface $transport, RawMessage $email, ?Envelope $envelope): void
    {
        $transport->send($email, $envelope);
    }
}
