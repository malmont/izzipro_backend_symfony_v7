<?php

namespace App\ESG\UseCase\Referential;

use App\ESG\DTO\Output\ReferentialOutputDTO;
use App\ESG\Entity\CertificationReferential;
use App\ESG\Entity\EsgCompany;
use App\Services\TenantEntityManagerProvider;

class ListReferentialsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @return ReferentialOutputDTO[]
     */
    public function execute(?EsgCompany $company = null): array
    {
        $em = $this->emProvider->getEntityManager();
        $referentials = $em->getRepository(CertificationReferential::class)->findAllActive();

        return array_map(
            fn(CertificationReferential $ref) => new ReferentialOutputDTO($ref, $company),
            $referentials
        );
    }
}
