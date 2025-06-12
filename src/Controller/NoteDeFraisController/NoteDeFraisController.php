<?php

namespace App\Controller\NoteDeFraisController;

use App\Entity\NoteDeFrais;
use App\Entity\Collections;
use App\Dto\NoteDeFraisInputDTO;
use App\UseCase\NoteDeFraisUseCase\GetNotesDeFraisByCollectionUseCase;
use App\UseCase\NoteDeFraisUseCase\CreateNoteDeFraisUseCase;
use App\UseCase\NoteDeFraisUseCase\UpdateNoteDeFraisUseCase;
use App\UseCase\NoteDeFraisUseCase\DeleteNoteDeFraisUseCase;
use App\Services\TenantCacheService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Cache\ItemInterface;

class NoteDeFraisController extends AbstractController
{
    private GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase;
    private CreateNoteDeFraisUseCase $createNoteDeFraisUseCase;
    private UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase;
    private DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase;
    private TenantCacheService $cache;

    public function __construct(
        GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase,
        CreateNoteDeFraisUseCase $createNoteDeFraisUseCase,
        UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase,
        DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase,
        TenantCacheService $cache
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
        $cacheKey = 'notes_de_frais_collection_' . $collection->getId();

        $notes = $this->cache->get(
            $cacheKey,
            function(ItemInterface $item) use ($collection, $host) {
                $item->expiresAfter(3600);
                $item->tag(['notes_de_frais']);
                return $this->getNotesDeFraisByCollectionUseCase->execute($collection, $host);
            },
            /* ttl */ 3600,
            /* extraTags */ ['notes_de_frais']
        );

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
            $data['description'] ?? '',
            isset($data['montant']) ? (float)$data['montant'] : 0.0,
            $data['date'] ?? null,
            $data['typeNoteDeFraisId'] ?? null
        );

        $this->createNoteDeFraisUseCase->execute($collection, $inputDTO);

        // L'invalidation est gérée par l'event subscriber existant, donc on ne l'ajoute pas ici.

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
            $data['description'] ?? '',
            isset($data['montant']) ? (float)$data['montant'] : 0.0,
            $data['date'] ?? null,
            $data['typeNoteDeFraisId'] ?? null
        );

        $this->updateNoteDeFraisUseCase->execute($note, $inputDTO);

        // Invalidation gérée ailleurs
        return new JsonResponse(['success' => 'Note de frais updated'], JsonResponse::HTTP_OK);
    }

    #[Route('/api/notes-de-frais/{id}', name: 'delete_note_de_frais', methods: ['DELETE'])]
    public function deleteNoteDeFrais(NoteDeFrais $note): JsonResponse
    {
        $this->deleteNoteDeFraisUseCase->execute($note);

        // Invalidation gérée par l'event subscriber existant
        return new JsonResponse(['success' => 'Note de frais deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
