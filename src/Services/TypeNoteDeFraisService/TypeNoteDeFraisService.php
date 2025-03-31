<?php

namespace App\Services\TypeNoteDeFraisService;

use App\Repository\TypeNoteDeFraisRepository;
use App\Dto\TypeNoteDeFraisDTO;

class TypeNoteDeFraisService
{
    private TypeNoteDeFraisRepository $repository;

    public function __construct(TypeNoteDeFraisRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return TypeNoteDeFraisDTO[]
     */
    public function getAllDTOs(): array
    {
        $entities = $this->repository->findAll();

        return array_map(
            fn($entity) => TypeNoteDeFraisDTO::fromEntity($entity),
            $entities
        );
    }
}
