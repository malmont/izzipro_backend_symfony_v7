<?php
namespace App\UseCase\FraisDePortUseCase;

use App\Entity\Commande;
use App\Entity\FraisDePort;
use App\Gestion\Services\GestionConflictException;
use App\Services\FraisDePortService\FraisDePortService;
use App\Services\TenantEntityManagerProvider;

/** PATCH /api/commandes/{id}/frais-de-port (lot EDIT, 12/11/2026) */
class UpdateFraisDePortUseCase
{
    public function __construct(private readonly FraisDePortService $fraisDePortService, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @param array<string, mixed> $data */
    public function execute(int $commandeId, array $data): FraisDePort
    {
        $commande = $this->emProvider->getEntityManager()->find(Commande::class, $commandeId);
        if ($commande === null) {
            throw new GestionConflictException('COMMANDE_NOT_FOUND', 'Commande inconnue : ' . $commandeId, 404);
        }

        return $this->fraisDePortService->update($commande, $data);
    }
}
