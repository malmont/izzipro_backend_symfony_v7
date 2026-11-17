<?php

namespace App\LossPrevention\MessageHandler;

use App\LossPrevention\Message\CheckLossExitMessage;
use App\LossPrevention\Services\LossExitAlertService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Le contrôle léger d'une lecture à la sortie (lot L5-d), sur le site de la lecture ; une panne ne rejoue rien (le constat reste jugé après le rattrapage) */
#[AsMessageHandler]
final class CheckLossExitHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly LossExitAlertService $alerts, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(CheckLossExitMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Prévention des pertes : alerte de sortie, site introuvable', ['tenant' => $message->tenantCode, 'event' => $message->eventId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        try {
            $this->alerts->check($message->eventId, $message->requestedBy);
        } catch (\Throwable $e) {
            // jamais le message de l'exception (il peut porter une donnée) : sa classe suffit ; rien n'est rejoué
            $this->logger->warning('Prévention des pertes : alerte de sortie non contrôlée (' . $e::class . ').');
        }
    }
}
