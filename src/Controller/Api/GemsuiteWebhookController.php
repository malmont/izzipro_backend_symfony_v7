<?php
// src/Controller/Api/GemsuiteWebhookController.php

namespace App\Controller\Api;

use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use App\Services\GemsuiteImporterService\GemsuiteRentalWebhookService;

class GemsuiteWebhookController extends AbstractController
{
    public function __construct(
        private GemsuiteSyncHandler $syncHandler,
        private GemsuiteCompanySyncHandler $companySyncHandler,
        private LoggerInterface $logger,
        private GemsuiteRentalWebhookService $rentalWebhookService
    ) {}


    #[Route('/api/webhooks/gemsuite/{tenant_code}', name: 'api_webhook_gemsuite_test', methods: ['POST', 'PUT', 'GET'])]
    #[Route('/api/webhooks/gemsuite/{tenant_code}/{endpoint}/{id}', name: 'api_webhook_gemsuite_event', methods: ['POST', 'PUT', 'GET'])]
    public function handleWebhook(
        \Symfony\Component\HttpFoundation\Request $request,
        string $tenant_code,
        ?string $endpoint = null,
        ?string $id = null
    ): Response {
        $this->logger->info(sprintf(
            'Tentative d\'appel Webhook GemSuite sur le tenant "%s" (GemSuite désactivé en mode Standalone).',
            $tenant_code
        ));

        return $this->json([
            'status' => 'disabled',
            'message' => 'L\'intégration GemSuite est désactivée sur cette version Standalone.'
        ], Response::HTTP_FORBIDDEN);
    }
}
