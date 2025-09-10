<?php
namespace App\UseCase\EmploiUseCase;

use App\Dto\EmploiOutputDto;
use App\Services\EmploiService\EmploiService;

class GetAllEmploisUseCase
{
    private EmploiService $emploiService;
    public function __construct(EmploiService $emploiService) { $this->emploiService = $emploiService; }

    /**
     * @return EmploiOutputDto[]
     */
    public function execute(string $locale): array 
    { 
        $emplois = $this->emploiService->getAllEmplois(); 
        return array_map(
            fn($emploi) => new EmploiOutputDto($emploi, $locale),
            $emplois
        );
    }
}