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
        private LoggerInterface $logger
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
                
                $tenantEm->flush();
                
                $this->logger->info('[Job Success] IMPORTATION TERMINÉE AVEC SUCCÈS ! LE SITE EST PRÊT.');
            }
        } catch (\Throwable $e) {
            $this->logger->error('[FinalizeJob Error] ' . $e->getMessage());
        }
    }
}