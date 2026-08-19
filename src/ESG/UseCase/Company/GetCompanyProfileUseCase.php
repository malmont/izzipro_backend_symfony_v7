<?php

namespace App\ESG\UseCase\Company;

use App\ESG\DTO\Output\CompanyOutputDTO;
use App\ESG\Entity\EsgUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetCompanyProfileUseCase
{
    public function execute(EsgUser $user): CompanyOutputDTO
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        return new CompanyOutputDTO($company);
    }
}
