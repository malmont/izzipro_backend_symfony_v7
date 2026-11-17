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
    /** Au-delà, le retard du contrôle est un avertissement */
    public const LATE_SECONDS = 60;

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
        // le retard RÉEL du contrôle, à chaque passage (demande du chef de projet, 17/11/2026) : de quoi décider, chiffres en main, si
        // l'alerte mérite une file à elle. En avertissement au-delà de 60 s ; jamais une donnée du site (le site, le nombre de secondes)
        if ($message->dueAt !== null) {
            $late = max(0, time() - $message->dueAt);
            $this->logger->log($late > self::LATE_SECONDS ? 'warning' : 'info', sprintf('Prévention des pertes : contrôle d\'alerte de sortie avec %d s de retard (file email).', $late),
                ['tenant' => $message->tenantCode, 'lateSeconds' => $late, 'exercise' => $message->requestedBy !== null]);
        }
        try {
            $this->alerts->check($message->eventId, $message->requestedBy);
        } catch (\Throwable $e) {
            // jamais le message de l'exception (il peut porter une donnée) : sa classe suffit ; rien n'est rejoué
            $this->logger->warning('Prévention des pertes : alerte de sortie non contrôlée (' . $e::class . ').');
        }
    }
}
