<?php

namespace App\UseCase\CommandeUseCase;

use App\Entity\Commande;
use App\Services\CommandeService\CommandeService;

/** PATCH /api/commandes/{id} : modification d'une commande fournisseur ouverte (lot G6, 10/11/2026) */
class UpdateCommandeUseCase
{
    public function __construct(private readonly CommandeService $commandeService)
    {
    }

    /** @param array<string, mixed> $fields champs validés présents seulement (budget, name, date, exchangeRate) */
    public function execute(Commande $commande, array $fields): Commande
    {
        return $this->commandeService->updateCommande($commande, $fields);
    }
}
