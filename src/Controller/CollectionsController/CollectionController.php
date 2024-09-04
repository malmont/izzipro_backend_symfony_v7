<?php

namespace App\Controller\CollectionsController;

use App\Dto\CollectionInputDTO;
use App\UseCase\CollectionUseCase\CreateCollectionUseCase;
use App\UseCase\CollectionUseCase\GetCollectionsUseCase;
use App\UseCase\CollectionUseCase\DeleteCollectionUseCase;
use App\Entity\Collections;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class CollectionController extends AbstractController
{
    private CreateCollectionUseCase $createCollectionUseCase;
    private GetCollectionsUseCase $getCollectionsUseCase;
    private DeleteCollectionUseCase $deleteCollectionUseCase;

    public function __construct(
        CreateCollectionUseCase $createCollectionUseCase,
        GetCollectionsUseCase $getCollectionsUseCase,
        DeleteCollectionUseCase $deleteCollectionUseCase
    ) {
        $this->createCollectionUseCase = $createCollectionUseCase;
        $this->getCollectionsUseCase = $getCollectionsUseCase;
        $this->deleteCollectionUseCase = $deleteCollectionUseCase;
    }

    #[Route('/api/createcollections', name: 'create_collection', methods: ['POST'])]
    public function createCollection(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$this->getUser()) {
            return new JsonResponse(['error' => 'Unauthenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $inputDTO = new CollectionInputDTO($data);
        $collectionDTO = $this->createCollectionUseCase->execute($inputDTO);

        return $this->json($collectionDTO, Response::HTTP_CREATED);
    }

    #[Route('/api/collections', name: 'get_collections', methods: ['GET'])]
    public function getCollections(): JsonResponse
    {
        $collections = $this->getCollectionsUseCase->execute();
        return $this->json($collections);
    }

    #[Route('/api/collections/{id}', name: 'delete_collection', methods: ['DELETE'])]
    public function deleteCollection(Collections $collection): JsonResponse
    {
        $this->deleteCollectionUseCase->execute($collection);
        return $this->json(['message' => 'Collection deleted successfully'], Response::HTTP_OK);
    }
}
