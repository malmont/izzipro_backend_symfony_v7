<?php
namespace App\UseCase\TransporteurUseCase;

use App\Entity\Transporteur;
use App\Gestion\Services\GestionConflictException;
use App\Services\TenantEntityManagerProvider;
use App\Services\TransporteurService\TransporteurService;

/** PATCH /api/transporteurs/{id} (lot EDIT, 12/11/2026) */
class UpdateTransporteurUseCase
{
    public function __construct(private readonly TransporteurService $transporteurService, private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    /** @param array<string, mixed> $data */
    public function execute(int $id, array $data): Transporteur
    {
        $transporteur = $this->emProvider->getEntityManager()->find(Transporteur::class, $id);
        if ($transporteur === null) {
            throw new GestionConflictException('TRANSPORTEUR_NOT_FOUND', 'Transporteur inconnu : ' . $id, 404);
        }

        return $this->transporteurService->update($transporteur, $data);
    }
}
