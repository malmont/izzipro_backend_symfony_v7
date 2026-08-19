<?php

namespace App\ESG\UseCase\Diagnostic;

use App\ESG\DTO\Output\SessionOutputDTO;
use App\ESG\Entity\DiagnosticSession;
use App\ESG\Entity\EsgUser;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ListSessionsUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider
    ) {
    }

    /**
     * @return SessionOutputDTO[]
     */
    public function execute(EsgUser $user): array
    {
        $company = $user->getCompany();
        if (!$company) {
            throw new NotFoundHttpException('Aucune entreprise associée à cet utilisateur.');
        }

        $em = $this->emProvider->getEntityManager();
        $sessions = $em->getRepository(DiagnosticSession::class)->findByCompanyOrdered($company);

        $dtos = [];
        foreach ($sessions as $session) {
            $dtos[] = new SessionOutputDTO($session);
        }

        return $dtos;
    }
}
