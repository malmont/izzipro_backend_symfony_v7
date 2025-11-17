<?php
// src/Message/ProcessGemsuiteEntityJob.php

namespace App\Message;

class ProcessGemsuiteEntityJob implements TenantJobInterface
{
    public function __construct(
        private int $tenantId,
        private int $syncJobId,
        private string $entityType, 
        private array $entityData 
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

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityData(): array
    {
        return $this->entityData;
    }
}