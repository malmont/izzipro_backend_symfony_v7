<?php

namespace App\Services\EmailConfigurationService;

use App\Entity\EmailConfiguration;
use App\Message\SendTenantEmailMessage;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'prod'
    ) {
    }

    public function deliver(SendTenantEmailMessage $message): void
    {
        $this->deliverRaw($message->email, $message->envelope, $message->tenantCode, $message->tenantDb, $message->configId);
    }

    /** Envoi par le serveur du site (configId relu dans sa base) ou de la plateforme (configId null) */
    public function deliverRaw(RawMessage $email, ?Envelope $envelope, ?string $tenantCode, ?string $tenantDb, ?int $configId): void
    {
        // Domaines réservés (RFC 2606 / 6761 : example.com, *.invalid, *.test…) : jamais livrables, le serveur les refuse
        // (554) ; ils servent aux comptes de démonstration et d'essai. Destinataires retirés, envoi abandonné s'il n'en
        // reste aucun. Les tests gardent ces adresses (transport null://, messages lus par les tests).
        if ($envelope !== null && $this->environment !== 'test') {
            $deliverable = array_values(array_filter($envelope->getRecipients(), fn ($a) => !self::isReserved($a->getAddress())));
            if ($deliverable === []) {
                $this->logger->info('[Email] Non envoyé : destinataire(s) d\'un domaine réservé (démonstration, essai)', ['site' => $tenantCode]);

                return;
            }
            if (count($deliverable) !== count($envelope->getRecipients())) {
                $envelope = new Envelope($envelope->getSender(), $deliverable);
            }
        }
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

    /** Adresse d'un domaine réservé, qui ne reçoit jamais de courrier (RFC 2606 et 6761) */
    public static function isReserved(string $address): bool
    {
        $domain = strtolower((string) substr(strrchr($address, '@') ?: '', 1));

        return $domain === '' || in_array($domain, ['example.com', 'example.net', 'example.org'], true)
            || (bool) preg_match('/(^|\.)(example\.(com|net|org)|example|invalid|test|localhost)$/', $domain);
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
