<?php

namespace App\ESG\UseCase\Company;

use App\ESG\DTO\Input\CompanyProfileInputDTO;
use App\ESG\DTO\Output\CompanyOutputDTO;
use App\ESG\Entity\EsgUser;
use App\ESG\Enum\SectorEnum;
use App\ESG\Enum\SizeEnum;
use App\ESG\Enum\TerritoryEnum;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateCompanyProfileUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    public function execute(EsgUser $user, CompanyProfileInputDTO $dto): CompanyOutputDTO
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $company->setName($dto->name);
        $company->setSector(SectorEnum::from($dto->sector));
        $company->setSizeCategory(SizeEnum::from($dto->sizeCategory));
        $company->setTerritory(TerritoryEnum::from($dto->territory));
        $company->setExistingCertifications($dto->existingCertifications);
        $company->setContactEmail($dto->contactEmail);
        $company->setCity($dto->city);
        $company->setWebsite($dto->website);

        $em = $this->emProvider->getEntityManager();
        $em->flush();

        return new CompanyOutputDTO($company);
    }
}
