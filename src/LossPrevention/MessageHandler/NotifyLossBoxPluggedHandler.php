<?php

namespace App\LossPrevention\MessageHandler;

use App\LossPrevention\Message\NotifyLossBoxPluggedMessage;
use App\LossPrevention\Services\LossBoxPluggedNotifier;
use App\LossPrevention\Master\LossPairingRepository;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Le worker des courriels (file `email`) prévient le site acheteur : son boîtier est branché (lot L6-b) */
#[AsMessageHandler]
final class NotifyLossBoxPluggedHandler
{
    public function __construct(private readonly LossPairingRepository $pairings, private readonly TenantEntityManagerProvider $emProvider, private readonly LossBoxPluggedNotifier $notifier, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(NotifyLossBoxPluggedMessage $message): void
    {
        // le site est relu dans la base maître SANS cache (comme le jumelage) : une fiche périmée n'enverrait pas l'avis à une autre base
        $tenant = $this->pairings->tenant($message->tenantCode);
        if ($tenant === null) {
            $this->logger->error('Prévention des pertes : boîtier branché, site introuvable', ['tenant' => $message->tenantCode, 'shipment' => $message->shipmentId]);

            return;
        }
        $this->emProvider->switchTenant($tenant['dbname'], $message->tenantCode);
        try {
            $message->what === NotifyLossBoxPluggedMessage::CANCELLED ? $this->notifier->cancelled($message->shipmentId) : $this->notifier->notify($message->shipmentId);
        } catch (\Throwable $e) {
            $this->logger->warning('Prévention des pertes : avis « boîtier branché » non envoyé (' . $e::class . ').');
        }
    }
}
