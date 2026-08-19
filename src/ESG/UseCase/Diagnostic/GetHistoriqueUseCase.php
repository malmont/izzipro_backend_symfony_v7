<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Output\HistoriqueSessionOutputDTO;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgUser;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetHistoriqueUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @return HistoriqueSessionOutputDTO[]
     */
    public function execute(EsgUser $user): array
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $em = $this->emProvider->getEntityManager();
        $sessions = $em->getRepository(DiagnosticSession::class)->findCompletedByCompanyOrderedByCompletedAt($company);

        $dtos = [];
        foreach ($sessions as $session) {
            $dtos[] = new HistoriqueSessionOutputDTO($session);
        }

        return $dtos;
    }
}
