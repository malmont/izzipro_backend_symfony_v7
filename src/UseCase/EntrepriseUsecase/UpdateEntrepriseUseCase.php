<?php

namespace App\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\EntrepriseService\EntrepriseService;

class UpdateEntrepriseUseCase
{
    private EntrepriseService $entrepriseService;

    public function __construct(EntrepriseService $entrepriseService)
    {
        $this->entrepriseService = $entrepriseService;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function execute(int $id, array $data, string $host, string $locale): ?EntrepriseDto
    {
        return $this->entrepriseService->updateEntreprise($id, $data, $host, $locale);
    }
}
