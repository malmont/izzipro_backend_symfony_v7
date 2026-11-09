<?php
namespace App\UseCase\CollectionUseCase;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTOMessage;
use App\Services\CollectionService\CollectionService;

class CreateCollectionUseCase
{
    private CollectionService $collectionService;

    public function __construct(CollectionService $collectionService)
    {
        $this->collectionService = $collectionService;
    }

    public function execute(CollectionInputDTO $inputDTO): \App\Entity\Collections
    {
        return $this->collectionService->createCollection($inputDTO); // l'entité créée (lot G4, 09/11/2026 ; avant : une chaîne « Collection creer avec succes »)
    }
}
