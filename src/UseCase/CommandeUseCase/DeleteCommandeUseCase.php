<?php

namespace App\UseCase\CommandeUseCase;

use App\Entity\Commande;
use App\Services\GestionService\PeriodGuard;
use App\Services\TenantEntityManagerProvider;

/**
 * DELETE /api/commandes/{id} (route dédiée depuis le lot P1, 10/11/2026 ; avant : API Platform) : refusée si la commande est figée
 * ou datée dans une période fermée (409) ; les produits sont détachés (clé étrangère ON DELETE SET NULL, lot G4), les
 * statistiques figées et le frais de port suivent (ON DELETE CASCADE).
 */
class DeleteCommandeUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly PeriodGuard $guard, private readonly ?\App\Services\CommandeService\CommandeLineService $lines = null)
    {
    }

    public function execute(Commande $commande): void
    {
        $this->guard->assertCommandeWritable($commande);
        $em = $this->emProvider->getEntityManager();
        // lignes d'achat supprimées avec la commande (ON DELETE CASCADE, lot L0) : les raccourcis de leurs produits sont recalculés ensuite
        $products = array_map(fn ($l) => $l->getProduct(), $em->getRepository(\App\Entity\CommandeLine::class)->forCommande($commande));
        $em->remove($commande);
        $em->flush();
        foreach ($products as $product) {
            $em->refresh($product);
            $this->lines?->refreshShortcuts($product);
        }
    }
}
