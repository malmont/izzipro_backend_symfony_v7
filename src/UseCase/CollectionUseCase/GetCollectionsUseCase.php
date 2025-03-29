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

    public function execute(string $host): array
    {
        return $this->collectionService->getCollections( $host);
    }
}
