<?php
// src/MessageHandler/FinalizeSyncJobHandler.php

namespace App\MessageHandler;

use App\Entity\SyncJob;
use App\Message\FinalizeSyncJob;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FinalizeSyncJobHandler
{
    public function __construct(
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger,
        private \Symfony\Contracts\HttpClient\HttpClientInterface $client,
        private \App\Services\GemsuiteImporterService\GemsuiteRentalWorkaroundService $workaroundService,
        private string $gemsuiteApiUrl
    ) {
    }

    public function __invoke(FinalizeSyncJob $message)
    {
        $this->logger->info('[FinalizeJob] Début de la finalisation...');

        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        if (!$tenant) {
            return;
        }

        try {
            $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);
            $tenantEm = $this->emProvider->getEntityManager();
            
            $syncJob = $tenantEm->getRepository(SyncJob::class)->find($message->getSyncJobId());
            
            if ($syncJob) {
                $syncJob->setStatus('completed');
                $syncJob->setCurrentStep('Terminé !');
                
                if ($syncJob->getTotalItems() > 0) {
                    $syncJob->setProcessedItems($syncJob->getTotalItems());
                }

                // TODO: TEMP WORKAROUND - Full Sync Rentals
                $this->syncRentalsData($tenant['code']);
                
                $tenantEm->flush();
                
                $this->logger->info('[Job Success] IMPORTATION TERMINÉE AVEC SUCCÈS ! LE SITE EST PRÊT.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('[FinalizeJob Error] ' . $e->getMessage());
        }
    }

    private function syncRentalsData(string $tenantCode): void
    {
        $token = $this->tenantManager->getTenantToken($tenantCode);
        if (!$token) return;

        try {
            $this->logger->info(sprintf('[FinalizeJob] Syncing Vehicles for Workaround on tenant "%s"', $tenantCode));
            $vehiclesResponse = $this->client->request('GET', $this->gemsuiteApiUrl . 'vehicles', [
                'auth_bearer' => $token,
            ]);
            
            // Assuming response looks like {"data": [...]}
            $vehiclesData = $vehiclesResponse->toArray()['data'] ?? [];
            if (!empty($vehiclesData)) {
                $this->workaroundService->syncVehicles($vehiclesData);
            }

            $this->logger->info(sprintf('[FinalizeJob] Syncing Rentals for Workaround on tenant "%s"', $tenantCode));
            $rentalsResponse = $this->client->request('GET', $this->gemsuiteApiUrl . 'rentals', [
                'auth_bearer' => $token,
            ]);
            
            $rentalsData = $rentalsResponse->toArray()['data'] ?? [];
            foreach ($rentalsData as $rentalData) {
                if (isset($rentalData['vehicle_id']) && isset($rentalData['appointments'])) {
                    $this->workaroundService->syncRentalsForVehicle((int)$rentalData['vehicle_id'], $rentalData['appointments']);
                }
            }
            
            $this->logger->info('[FinalizeJob] Workaround location sync successful.');
        } catch (\Throwable $e) {
            $this->logger->error('[FinalizeJob] Rentals Sync failed: ' . $e->getMessage());
        }
    }
}