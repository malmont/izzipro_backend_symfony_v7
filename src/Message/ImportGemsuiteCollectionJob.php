<?php
// src/Message/ImportGemsuiteCollectionJob.php
namespace App\Message;


class ImportGemsuiteCollectionJob implements TenantJobInterface
{
    public function __construct(
        private int $tenantId,
        private string $gemsuiteToken,
        private int $syncJobId,
        private string $collectionType, 
        private int $page = 1 
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

    public function getSyncJobId(): int
    {
        return $this->syncJobId;
    }

    public function getCollectionType(): string
    {
        return $this->collectionType;
    }

    public function getPage(): int
    {
        return $this->page;
    }
}