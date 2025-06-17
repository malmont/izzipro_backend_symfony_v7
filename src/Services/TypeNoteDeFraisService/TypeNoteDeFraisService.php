<?php
namespace App\Services\TypeNoteDeFraisService;

use App\Dto\TypeNoteDeFraisDTO;
use App\Entity\TypeNoteDeFrais; // <-- On importe l'entité
use App\Services\TenantEntityManagerProvider; // <-- On importe notre provider

class TypeNoteDeFraisService
{
    // MODIFICATION 1 : Le service ne dépend plus que du provider
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * @return TypeNoteDeFraisDTO[]
     */
    public function getAllDTOs(): array
    {
        // MODIFICATION 2 : On récupère l'EM et le repository ici
        $em = $this->emProvider->getEntityManager();
        $repository = $em->getRepository(TypeNoteDeFrais::class);

        // On utilise le repository obtenu depuis l'EM du tenant
        $entities = $repository->findAll();

        // Le reste de votre logique est inchangée
        return array_map(
            fn($entity) => TypeNoteDeFraisDTO::fromEntity($entity),
            $entities
        );
    }
}