<?php
// src/Message/FinalizeSyncJob.php

namespace App\Message;

class FinalizeSyncJob implements TenantJobInterface
{
    public function __construct(
        private int $tenantId,
        private int $syncJobId
    ) {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getSyncJobId(): int
    {
        return $this->syncJobId;
    }
}