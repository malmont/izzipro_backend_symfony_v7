<?php

namespace App\Controller\NoteDeFraisController;

use App\Entity\NoteDeFrais;
use App\Entity\Collections;
use App\Dto\NoteDeFraisInputDTO;
use App\UseCase\NoteDeFraisUseCase\GetNotesDeFraisByCollectionUseCase;
use App\UseCase\NoteDeFraisUseCase\CreateNoteDeFraisUseCase;
use App\UseCase\NoteDeFraisUseCase\UpdateNoteDeFraisUseCase;
use App\UseCase\NoteDeFraisUseCase\DeleteNoteDeFraisUseCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;


class NoteDeFraisController extends AbstractController
{
    private GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase;
    private CreateNoteDeFraisUseCase $createNoteDeFraisUseCase;
    private UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase;
    private DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase;
    private CacheInterface $cache;

    public function __construct(
        GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase,
        CreateNoteDeFraisUseCase $createNoteDeFraisUseCase,
        UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase,
        DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase,
        CacheInterface $cache
    ) {
        $this->getNotesDeFraisByCollectionUseCase = $getNotesDeFraisByCollectionUseCase;
        $this->createNoteDeFraisUseCase = $createNoteDeFraisUseCase;
        $this->updateNoteDeFraisUseCase = $updateNoteDeFraisUseCase;
        $this->deleteNoteDeFraisUseCase = $deleteNoteDeFraisUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'get_notes_de_frais_by_collection', methods: ['GET'])]
    public function getNotesDeFraisByCollection(Collections $collection, Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        // Construction d'une clé de cache basée sur l'ID de la collection
        $cacheKey = 'notes_de_frais_collection_' . $collection->getId();

        $notes = $this->cache->get($cacheKey, function (ItemInterface $item) use ($collection, $host) {
            $item->expiresAfter(3600); // Cache expire après 1 heure
            $item->tag(['notes_de_frais']);
            return $this->getNotesDeFraisByCollectionUseCase->execute($collection, $host);
        });

        return new JsonResponse($notes, JsonResponse::HTTP_OK);
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'create_note_de_frais', methods: ['POST'])]
    public function createNoteDeFrais(Collections $collection, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $inputDTO = new NoteDeFraisInputDTO(
            $data['description'],
            (float)$data['montant'],
            $data['date'],
            $data['typeNoteDeFraisId']
        );

        $this->createNoteDeFraisUseCase->execute($collection, $inputDTO);

        return new JsonResponse(['success' => 'Note de frais created'], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/notes-de-frais/{id}', name: 'update_note_de_frais', methods: ['PUT'])]
    public function updateNoteDeFrais(NoteDeFrais $note, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $inputDTO = new NoteDeFraisInputDTO(
            $data['description'],
            (float)$data['montant'],
            $data['date'],
            $data['typeNoteDeFraisId']
        );

        $this->updateNoteDeFraisUseCase->execute($note, $inputDTO);

        return new JsonResponse(['success' => 'Note de frais updated'], JsonResponse::HTTP_OK);
    }

    #[Route('/api/notes-de-frais/{id}', name: 'delete_note_de_frais', methods: ['DELETE'])]
    public function deleteNoteDeFrais(NoteDeFrais $note): JsonResponse
    {
        $this->deleteNoteDeFraisUseCase->execute($note);
        return new JsonResponse(['success' => 'Note de frais deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
