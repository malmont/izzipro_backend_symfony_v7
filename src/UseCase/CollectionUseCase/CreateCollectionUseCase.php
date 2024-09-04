<?php
namespace App\UseCase\CollectionUseCase;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTO;
use App\Services\CollectionService\CollectionService;

class CreateCollectionUseCase
{
    private CollectionService $collectionService;

    public function __construct(CollectionService $collectionService)
    {
        $this->collectionService = $collectionService;
    }

    public function execute(CollectionInputDTO $inputDTO): CollectionOutputDTO
    {
        $collection = $this->collectionService->createCollection($inputDTO);
        return CollectionOutputDTO::fromEntity($collection);
    }
}
