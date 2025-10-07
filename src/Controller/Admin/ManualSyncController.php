<?php
// src/Controller/Admin/ManualSyncController.php

namespace App\Controller\Admin;

use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use App\Services\GemsuiteImporterService\GemsuiteImporter;
use App\Services\TenantConnectionManager;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ManualSyncController extends AbstractController
{
    public function __construct(
        private GemsuiteImporter $importer, 
        private GemsuiteCompanySyncHandler $companySyncHandler, 
        private TenantConnectionManager $tenantManager,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/admin/sync-gemsuite', name: 'admin_sync_gemsuite')]
    public function syncAll(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $tenantCode = $this->tenantManager->getCurrentTenantCode();
        if (!$tenantCode) {
            $this->addFlash('danger', 'Impossible de déterminer le tenant actuel.');
            return $this->redirectToRoute('admin'); 
        }

        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) {
            $this->addFlash('danger', sprintf('Aucun token GEM-SUITE n\'est configuré pour le tenant "%s".', $tenantCode));
            return $this->redirectToRoute('admin');
        }

        $this->addFlash('info', 'Lancement de la synchronisation complète pour le tenant ' . $tenantCode . '. Cette opération peut prendre plusieurs minutes.');

        try {
            $this->companySyncHandler->handleCompanyUpdate($tenantCode);
            $this->addFlash('success', 'Informations de l\'entreprise et du slider synchronisées.');
            $this->importer->importDataForTenant($tenantCode, $token);
            $this->addFlash('success', 'Clients, catégories et produits synchronisés.');

            $this->addFlash('info', 'Synchronisation complète terminée avec succès !');

        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de la synchronisation manuelle : ' . $e->getMessage(), ['exception' => $e]);
            $this->addFlash('danger', 'Une erreur est survenue pendant la synchronisation : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin'); 
    }
}