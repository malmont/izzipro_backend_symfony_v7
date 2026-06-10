<?php

namespace App\Message;

class SyncCategoryProductsJob implements TenantJobInterface
{
    public function __construct(
        private int $tenantId,
        private int $gemsuiteCategoryId
    ) {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getGemsuiteCategoryId(): int
    {
        return $this->gemsuiteCategoryId;
    }
}
