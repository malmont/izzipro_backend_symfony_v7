<?php
namespace App\UseCase\CollectionUseCase;

use App\Entity\Collections;
use App\Services\CollectionService\CollectionService;

class DeleteCollectionUseCase
{
    private CollectionService $collectionService;

    public function __construct(CollectionService $collectionService)
    {
        $this->collectionService = $collectionService;
    }

    public function execute(Collections $collection): void
    {
        $this->collectionService->deleteCollection($collection);
    }
}
