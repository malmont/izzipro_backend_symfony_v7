<?php
// src/Controller/Api/GemsuiteWebhookController.php

namespace App\Controller\Api;

use App\Services\GemsuiteImporterService\GemsuiteCompanySyncHandler; 
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

class GemsuiteWebhookController extends AbstractController
{
    public function __construct(
        private GemsuiteSyncHandler $syncHandler,
        private GemsuiteCompanySyncHandler $companySyncHandler,
        private LoggerInterface $logger
    ) {
    }


    #[Route('/api/webhooks/gemsuite/{tenant_code}', name: 'api_webhook_gemsuite_test', methods: ['POST', 'PUT', 'GET'])]
    
    #[Route('/api/webhooks/gemsuite/{tenant_code}/{endpoint}/{id}', name: 'api_webhook_gemsuite_event', methods: ['POST', 'PUT', 'GET'])]
    public function handleWebhook(
        string $tenant_code,
        ?string $endpoint = null, 
        ?int $id = null  
    ): Response {
        

        if ($endpoint === null) {
            $this->logger->info(sprintf(
                'Requête de validation de webhook reçue pour le tenant "%s". Réponse 200 OK.',
                $tenant_code
            ));

            return $this->json(['status' => 'validation_received']);
        }

        $this->logger->info(sprintf(
            'Webhook reçu pour tenant "%s" | Endpoint: "%s" | ID: %d',
            $tenant_code,
            $endpoint,
            $id
        ));
        

        try {
            switch ($endpoint) {
                case 'products':
                    $this->syncHandler->handleProductUpdate($tenant_code, $id);
                    break;
                
                case 'categories':
                    $this->syncHandler->handleCategoryUpdate($tenant_code, $id);
                    break;

                case 'company':
                    $this->companySyncHandler->handleCompanyUpdate($tenant_code);
                    break;
                
                default:
                    $this->logger->warning(sprintf('Endpoint de webhook non géré : "%s"', $endpoint));
                    break;
            }

            return $this->json(['status' => 'processed', 'endpoint' => $endpoint, 'id' => $id]);

        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'Erreur lors du traitement du webhook pour tenant "%s": %s',
                $tenant_code,
                $e->getMessage()
            ));
            return $this->json(['status' => 'error', 'message' => 'Internal Server Error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
