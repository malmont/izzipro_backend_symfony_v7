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
        $this->addFlash('warning', 'La synchronisation GemSuite a été désactivée. Le projet v7 fonctionne désormais en autonomie complète.');
        return $this->redirectToRoute('admin');
    }
}