<?php

namespace App\UseCase\TypeFournisseurUseCase;

use App\Services\TypeFournisseurService\TypeFournisseurService;
use App\Dto\TypeFournisseurDTO;

class GetListTypeFournisseurUseCase
{
    private TypeFournisseurService $service;

    public function __construct(TypeFournisseurService $service)
    {
        $this->service = $service;
    }

    /**
     * @return TypeFournisseurDTO[]
     */
    public function execute(): array
    {
        return $this->service->getAllDTOs();
    }
}
