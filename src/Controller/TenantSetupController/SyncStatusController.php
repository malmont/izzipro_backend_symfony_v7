<?php
// src/Controller/SyncStatusController.php

namespace App\Controller\TenantSetupController;

use App\Entity\SyncJob;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SyncStatusController extends AbstractController
{
    #[Route('/setup/status/{tenantCode}/{syncJobId}/{finalUrl}', name: 'app_setup_status')]
    public function __invoke(
        string $tenantCode,
        int $syncJobId,
        string $finalUrl,
        TenantConnectionManager $connectionManager,
        TenantEntityManagerProvider $emProvider
    ): Response
    {
        $pdo = $connectionManager->getPdoMaster();
        $stmt = $pdo->prepare('SELECT dbname FROM tenants WHERE code = :code');
        $stmt->execute(['code' => $tenantCode]);
        $dbname = $stmt->fetchColumn();

        if (!$dbname) {
            throw $this->createNotFoundException("Boutique introuvable pour le code : " . $tenantCode);
        }

        try {
            $emProvider->switchTenant($dbname, $tenantCode);
            $tenantEm = $emProvider->getEntityManager();

            $syncJob = $tenantEm->getRepository(SyncJob::class)->find($syncJobId);
        } catch (\Throwable $e) {
            return $this->renderWaiting($tenantCode, $finalUrl);
        }

        if (!$syncJob) {
            return $this->renderWaiting($tenantCode, $finalUrl);
        }

        return $this->render('sync_status/index.html.twig', [
            'status' => $syncJob->getStatus(),
            'step' => $syncJob->getCurrentStep(),
            'percent' => $syncJob->getPercent(),
            'error' => $syncJob->getLastError(),
            'finalUrl' => base64_decode($finalUrl),
            'tenantCode' => $tenantCode
        ]);
    }

    /**
     * Une petite méthode pour afficher un état "en attente" si on ne trouve pas encore le job
     */
    private function renderWaiting(string $tenantCode, string $finalUrl): Response
    {
        return $this->render('sync_status/index.html.twig', [
            'status' => 'pending',
            'step' => 'Démarrage des services...',
            'percent' => 0,
            'error' => null,
            'finalUrl' => base64_decode($finalUrl),
            'tenantCode' => $tenantCode
        ]);
    }
}