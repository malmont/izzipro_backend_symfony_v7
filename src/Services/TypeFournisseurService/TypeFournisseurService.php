<?php

namespace App\Services\TypeFournisseurService;

use App\Dto\TypeFournisseurDTO;
use App\Entity\TypeFournisseur;
use App\Services\TenantEntityManagerProvider;

class TypeFournisseurService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    /**
     * @return TypeFournisseurDTO[]
     */
    public function getAllDTOs(): array
    {
        $em = $this->emProvider->getEntityManager();
        $entities = $em->getRepository(TypeFournisseur::class)->findAll();

        return array_map(function ($entity) {
            return TypeFournisseurDTO::fromEntity($entity);
        }, $entities);
    }
}
