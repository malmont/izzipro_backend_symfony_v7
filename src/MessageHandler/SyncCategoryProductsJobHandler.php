<?php

namespace App\MessageHandler;

use App\Message\SyncCategoryProductsJob;
use App\Services\GemsuiteImporterService\GemsuiteSyncHandler;
use App\Services\TenantConnectionManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncCategoryProductsJobHandler
{
    public function __construct(
        private TenantConnectionManager $tenantManager,
        private GemsuiteSyncHandler $syncHandler
    ) {
    }

    public function __invoke(SyncCategoryProductsJob $message): void
    {
        $tenant = $this->tenantManager->findTenantById($message->getTenantId());
        if (!$tenant) {
            return;
        }

        $this->syncHandler->syncCategoryProducts($tenant['code'], $message->getGemsuiteCategoryId());
    }
}
