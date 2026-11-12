<?php
namespace App\UseCase\FournisseurUseCase;

use App\Entity\Fournisseur;
use App\Gestion\Services\GestionConflictException;
use App\Services\FournisseurService\FournisseurService;
use App\Services\TenantEntityManagerProvider;

/** PATCH /api/fournisseurs/{id} (lot EDIT, 12/11/2026) */
class UpdateFournisseurUseCase
{
    public function __construct(private readonly FournisseurService $fournisseurService, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @param array<string, mixed> $data */
    public function execute(int $id, array $data): Fournisseur
    {
        $fournisseur = $this->emProvider->getEntityManager()->find(Fournisseur::class, $id);
        if ($fournisseur === null) {
            throw new GestionConflictException('FOURNISSEUR_NOT_FOUND', 'Fournisseur inconnu : ' . $id, 404);
        }

        return $this->fournisseurService->update($fournisseur, $data);
    }
}
