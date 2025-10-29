<?php

namespace App\UseCase\EntrepriseUsecase;

use App\Dto\EntrepriseDto;
use App\Services\EntrepriseService\EntrepriseService;

class CreateEntrepriseUseCase
{
    private EntrepriseService $entrepriseService;
    public function __construct( EntrepriseService $entrepriseService) {
        $this->entrepriseService = $entrepriseService;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function execute(array $data): EntrepriseDto
    {
        $dto = new EntrepriseDto();
        $dto->name = $data['name'] ?? null;
        $dto->logo = $data['logo'] ?? null;
        $dto->faviconUrl = $data['faviconUrl'] ?? null;
        $dto->email = $data['email'] ?? null;
        $dto->tel = $data['tel'] ?? null;
        $dto->website = $data['website'] ?? null;
        $dto->ein = $data['ein'] ?? null;
        $dto->tvaIntracommunautaire = $data['tvaIntracommunautaire'] ?? null;
        $dto->conditionOfUse = $data['conditionOfUse'] ?? null;
        $dto->LegalNotice = $data['LegalNotice'] ?? null;
        $dto->privacyPolicy = $data['privacyPolicy'] ?? null;
        $dto->adress = $data['adress'] ?? null;

        return $this->entrepriseService->createEntreprise($dto);
    }
}

