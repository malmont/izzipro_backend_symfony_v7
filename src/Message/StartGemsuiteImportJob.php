<?php
namespace App\Message;

class StartGemsuiteImportJob implements TenantJobInterface
{
    public function __construct(
        private int $tenantId,
        private string $gemsuiteToken, 
        private ?int $syncJobId = null 
    ) {
    }

    public function getTenantId(): int
    {
        return $this->tenantId;
    }

    public function getGemsuiteToken(): string
    {
        return $this->gemsuiteToken;
    }

    public function getSyncJobId(): ?int
    {
        return $this->syncJobId;
    }
}