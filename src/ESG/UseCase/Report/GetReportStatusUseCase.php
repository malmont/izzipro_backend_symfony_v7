<?php

namespace App\ESG\UseCase\Report;

use App\ESG\DTO\Output\ReportStatusOutputDTO;
use App\ESG\Entity\DiagnosticSession;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class GetReportStatusUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AuthorizationCheckerInterface $authorizationChecker
    ) {
    }

    public function execute(string $uuid): ReportStatusOutputDTO
    {
        $em = $this->emProvider->getEntityManager();
        
        /** @var DiagnosticSession|null $session */
        $session = $em->getRepository(DiagnosticSession::class)->findByUuid($uuid);
        if (!$session) {
            throw new NotFoundHttpException('Session de diagnostic introuvable.');
        }

        // Check permission via Voter
        if (!$this->authorizationChecker->isGranted('VIEW', $session)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas accès à ce rapport.');
        }

        $report = $session->getReport();
        if (!$report) {
            throw new NotFoundHttpException('Aucun rapport associé à cette session.');
        }

        return new ReportStatusOutputDTO($report);
    }
}
