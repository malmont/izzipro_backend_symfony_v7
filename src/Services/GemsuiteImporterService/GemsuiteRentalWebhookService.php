<?php

namespace App\Services\GemsuiteImporterService;

use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

class GemsuiteRentalWebhookService
{
    public function __construct(
        private GemsuiteRentalWorkaroundService $workaroundService,
        private TenantConnectionManager $tenantManager,
        private TenantEntityManagerProvider $emProvider,
        private LoggerInterface $logger
    ) {}

    /**
     * Called directly by the Webhook Controller when a rental is modified
     */
    public function handleRentalWebhook(string $tenantCode, int $vehicleId, array $appointments): void
    {
        $this->logger->info(sprintf(
            '[RentalWebhook] Processing rental update for vehicle ID: %d on tenant: %s',
            $vehicleId,
            $tenantCode
        ));

        $tenant = $this->tenantManager->findTenantByCode($tenantCode);
        if (!$tenant) {
            $this->logger->error(sprintf('[RentalWebhook] Tenant "%s" not found. Aborting.', $tenantCode));
            return;
        }

        $this->emProvider->switchTenant($tenant['dbname'], $tenant['code']);

        try {
            $this->workaroundService->syncRentalsForVehicle($vehicleId, $appointments);
            
            $this->logger->info(sprintf(
                '[RentalWebhook] Success for vehicle ID: %d',
                $vehicleId
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[RentalWebhook] Error processing vehicle ID %d: %s',
                $vehicleId,
                $e->getMessage()
            ));
            
            throw $e;
        }
    }
}
