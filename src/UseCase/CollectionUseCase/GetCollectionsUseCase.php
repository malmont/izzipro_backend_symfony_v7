<?php
namespace App\UseCase\CollectionUseCase;

use App\Services\CollectionService\CollectionService;

class GetCollectionsUseCase
{
    private CollectionService $collectionService;

    public function __construct(CollectionService $collectionService)
    {
        $this->collectionService = $collectionService;
    }

    public function execute(string $host, ?\App\Services\GestionService\GestionListQuery $query = null): array
    {
        return $this->collectionService->getCollections($host, $query); // {items, total} (lot G5, 10/11/2026)
    }
}
