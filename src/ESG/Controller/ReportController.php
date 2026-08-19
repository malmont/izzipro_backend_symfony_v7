<?php

namespace App\ESG\Controller;

use App\ESG\Entity\DiagnosticSession;
use App\ESG\UseCase\Report\GetReportStatusUseCase;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

#[Route('/api/boussole/sessions')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
        private readonly AuthorizationCheckerInterface $authorizationChecker
    ) {
    }

    #[Route('/{uuid}/report', name: 'esg_report_status', methods: ['GET'])]
    public function getStatus(string $uuid, GetReportStatusUseCase $useCase): Response
    {
        try {
            $output = $useCase->execute($uuid);
            return $this->json($output, Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

    #[Route('/{uuid}/report/download', name: 'esg_report_download', methods: ['GET'])]
    public function download(string $uuid, TenantEntityManagerProvider $emProvider): Response
    {
        $em = $emProvider->getEntityManager();
        
        /** @var DiagnosticSession|null $session */
        $session = $em->getRepository(DiagnosticSession::class)->findByUuid($uuid);
        if (!$session) {
            throw new NotFoundHttpException('Session de diagnostic introuvable.');
        }

        // Check permission via Voter
        if (!$this->authorizationChecker->isGranted('VIEW', $session)) {
            throw new AccessDeniedHttpException('Vous n\'avez pas l\'autorisation de télécharger ce rapport.');
        }

        $report = $session->getReport();
        if (!$report || $report->getStatus()->value !== 'ready' || !$report->getFilePath()) {
            throw new NotFoundHttpException('Rapport non généré ou indisponible.');
        }

        $pdfPath = $this->projectDir . '/public' . $report->getFilePath();
        if (!file_exists($pdfPath)) {
            throw new NotFoundHttpException('Le fichier PDF est introuvable sur le serveur.');
        }

        // Increment download count
        $report->incrementDownloadCount();
        $em->flush();

        return new BinaryFileResponse($pdfPath, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Rapport_Boussole_ESG_' . $uuid . '.pdf"'
        ]);
    }
}
