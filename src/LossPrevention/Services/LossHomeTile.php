<?php

namespace App\LossPrevention\Services;

use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Ce que la prévention des pertes donne à l'accueil de la gestion (lot L1-b) : le nombre de constats NOUVEAUX (jamais ouverts, hors reprise,
 * non décidés) et leur montant en jeu, pour la vignette « N nouveaux constats, X en jeu ». Aucun nom, aucun détail : deux nombres.
 * Rien quand le droit du module n'est pas accordé au site, ou quand il n'y a rien de nouveau. Une panne ici ne doit jamais casser l'accueil :
 * elle est consignée et la vignette est simplement absente.
 */
class LossHomeTile
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly LossSettingsService $settings, private readonly LoggerInterface $logger)
    {
    }

    /** @return array{count: int, amount: int, signature: string}|null `signature` change quand la liste des nouveaux constats change */
    public function newFindings(): ?array
    {
        try {
            if (!$this->settings->moduleGranted()) {
                return null;
            }
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                "SELECT COUNT(*) AS n, COALESCE(SUM(amount_at_stake), 0) AS amount, COALESCE(MAX(id), 0) AS last FROM loss_finding WHERE seen_at IS NULL AND backfill = false AND status = 'open'") ?: [];
            $count = (int) ($row['n'] ?? 0);

            return $count === 0 ? null : ['count' => $count, 'amount' => (int) $row['amount'], 'signature' => $count . ':' . (int) $row['last']];
        } catch (\Throwable $e) {
            $this->logger->error('Prévention des pertes : vignette de l\'accueil illisible : ' . $e->getMessage(), ['exception' => $e]);

            return null;
        }
    }
}
