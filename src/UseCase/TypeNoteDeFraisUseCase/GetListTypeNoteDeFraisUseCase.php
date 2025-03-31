<?php

namespace App\UseCase\TypeNoteDeFraisUseCase;

use App\Services\TypeNoteDeFraisService\TypeNoteDeFraisService;
use App\Dto\TypeNoteDeFraisDTO;

class GetListTypeNoteDeFraisUseCase
{
    private TypeNoteDeFraisService $service;

    public function __construct(TypeNoteDeFraisService $service)
    {
        $this->service = $service;
    }

    /**
     * @return TypeNoteDeFraisDTO[]
     */
    public function execute(): array
    {
        return $this->service->getAllDTOs();
    }
}
