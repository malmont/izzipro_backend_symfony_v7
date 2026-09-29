<?php

namespace App\MessageHandler;

use App\Message\LandingAiJobMessage;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\LandingAiUseCase\RunLandingAiJobUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Worker Messenger : sélectionne le tenant de la tâche, puis la traite.
 */
#[AsMessageHandler]
class LandingAiJobHandler
{
    public function __construct(
        private readonly TenantConnectionManager $tenantManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly RunLandingAiJobUseCase $runJob,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(LandingAiJobMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Assistant IA : tenant de la tâche introuvable', ['tenant' => $message->tenantCode, 'job' => $message->jobId]);
            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->runJob->execute($message->jobId);
    }
}
