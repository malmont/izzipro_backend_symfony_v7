<?php

namespace App\MessageHandler;

use App\Message\SendTenantEmailMessage;
use App\Services\EmailConfigurationService\TenantEmailDelivery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker « email » : envoie les courriels de tous les sites (rendez-vous, commandes, abonnements, contact, comptes,
 * Mémoires Vivantes…). Une erreur SMTP remonte : Messenger relance (30 s, 2 min, 8 min) puis range le message dans la
 * file « failed ».
 */
#[AsMessageHandler]
final class SendTenantEmailHandler
{
    public function __construct(private readonly TenantEmailDelivery $delivery, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(SendTenantEmailMessage $message): void
    {
        try {
            $this->delivery->deliver($message);
            $this->logger->info('[Email] Envoyé : ' . $message->describe());
        } catch (\Throwable $e) {
            $this->logger->error('[Email] Échec, nouvel essai prévu : ' . $message->describe() . ' — ' . $e->getMessage());

            throw $e;
        }
    }
}
