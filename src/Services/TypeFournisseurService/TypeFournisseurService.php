<?php

namespace App\Services\TypeFournisseurService;
use App\Repository\TypeFournisseurRepository;
use App\Dto\TypeFournisseurDTO;

class TypeFournisseurService
{
    private TypeFournisseurRepository $repository;

    public function __construct(TypeFournisseurRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return TypeFournisseurDTO[]
     */
    public function getAllDTOs(): array
    {
        $entities = $this->repository->findAll();

        return array_map(function ($entity) {
            return TypeFournisseurDTO::fromEntity($entity);
        }, $entities);
    }
}
