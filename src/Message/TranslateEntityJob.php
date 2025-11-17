<?php
// src/Message/TranslateEntityJob.php

namespace App\Message;


class TranslateEntityJob implements TenantJobInterface
{
    public function __construct(
        private int $tenantId,
        private string $entityClass, 
        private int $entityId  
    ) {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }
}