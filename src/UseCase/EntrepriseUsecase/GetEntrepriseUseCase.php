<?php
namespace App\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\EntrepriseService\EntrepriseService;

class GetEntrepriseUseCase
{
    private EntrepriseService $entrepriseService;
    public function __construct(EntrepriseService $entrepriseService) {
        $this->entrepriseService = $entrepriseService;
    }

    public function execute(int $id, string $host, string $locale): ?EntrepriseDto
    {
        return $this->entrepriseService->getEntrepriseByIdAndLocale($id, $host, $locale);
    }
}