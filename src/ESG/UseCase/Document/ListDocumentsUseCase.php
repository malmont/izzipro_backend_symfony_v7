<?php

namespace App\ESG\UseCase\Document;

use App\ESG\DTO\Output\EsgDocumentOutputDTO;
use App\ESG\Entity\EsgDocument;
use App\ESG\Enum\DomainEnum;
use App\ESG\Entity\EsgUser;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ListDocumentsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @return EsgDocumentOutputDTO[]
     */
    public function execute(EsgUser $user, ?string $domainStr = null): array
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $em = $this->emProvider->getEntityManager();
        $repo = $em->getRepository(EsgDocument::class);

        if ($domainStr) {
            $domain = DomainEnum::from($domainStr);
            $documents = $repo->findByCompanyAndDomain($company, $domain);
        } else {
            $documents = $repo->findByCompany($company);
        }

        $dtos = [];
        foreach ($documents as $doc) {
            $downloadUrl = '/api/boussole/documents/download/' . $doc->getId();
            $dtos[] = new EsgDocumentOutputDTO($doc, $downloadUrl);
        }

        return $dtos;
    }
}
