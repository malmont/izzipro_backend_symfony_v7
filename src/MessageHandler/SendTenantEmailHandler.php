<?php

namespace App\MessageHandler;

use App\Message\SendTenantEmailMessage;
use App\Services\EmailConfigurationService\TenantEmailDelivery;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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
            // « Envoyé » seulement si le message est réellement parti (lot EMAIL-JOURNAL, 13/11/2026) : des destinataires d'un domaine réservé
            // (comptes de démonstration et d'essai) sont écartés par la remise, qui l'a déjà écrit (« Non envoyé ») quand il n'en reste aucun
            $total = count($message->envelope->getRecipients());
            $sent = $this->delivery->deliver($message);
            if ($sent > 0) {
                $this->logger->info('[Email] Envoyé : ' . $message->describe() . ($sent < $total ? sprintf(' — %d remis, %d écarté(s) (domaine réservé)', $sent, $total - $sent) : ''));
            }
        } catch (TransportExceptionInterface $e) {
            // Refus définitif du serveur (5xx : adresse inconnue, domaine refusé…) : aucune relance, file « failed »
            if ($e->getCode() >= 500 && $e->getCode() < 600) {
                $this->logger->error('[Email] Refus définitif du serveur, sans relance : ' . $message->describe() . ' — ' . $e->getMessage());

                throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
            }
            $this->logger->error('[Email] Échec, nouvel essai prévu : ' . $message->describe() . ' — ' . $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[Email] Échec, nouvel essai prévu : ' . $message->describe() . ' — ' . $e->getMessage());

            throw $e;
        }
    }
}
