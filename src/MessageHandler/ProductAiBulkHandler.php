<?php

namespace App\MessageHandler;

use App\Message\ProductAiBulkMessage;
use App\Services\ProductAiService\ProductAiBulkService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Worker IA : lots de fiches, traductions et images produit (07/11/2026) ; même transport que l'assistant des pages */
#[AsMessageHandler]
class ProductAiBulkHandler
{
    public function __construct(private readonly TenantConnectionManager $tenantManager, private readonly TenantEntityManagerProvider $emProvider, private readonly ProductAiBulkService $bulk, private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ProductAiBulkMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if ($tenant === null || empty($tenant['dbname'])) {
            $this->logger->error('Lot IA produits : tenant introuvable', ['tenant' => $message->tenantCode, 'job' => $message->jobId]);

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], $message->tenantCode);
        $this->bulk->run($message->jobId);
    }
}
