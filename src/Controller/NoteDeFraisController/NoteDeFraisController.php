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

class NoteDeFraisController extends AbstractController
{
    private GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase;
    private CreateNoteDeFraisUseCase $createNoteDeFraisUseCase;
    private UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase;
    private DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase;

    public function __construct(
        GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase,
        CreateNoteDeFraisUseCase $createNoteDeFraisUseCase,
        UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase,
        DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase
    ) {
        $this->getNotesDeFraisByCollectionUseCase = $getNotesDeFraisByCollectionUseCase;
        $this->createNoteDeFraisUseCase = $createNoteDeFraisUseCase;
        $this->updateNoteDeFraisUseCase = $updateNoteDeFraisUseCase;
        $this->deleteNoteDeFraisUseCase = $deleteNoteDeFraisUseCase;
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'get_notes_de_frais_by_collection', methods: ['GET'])]
    public function getNotesDeFraisByCollection(Collections $collection): JsonResponse
    {
        $notes = $this->getNotesDeFraisByCollectionUseCase->execute($collection);
        return $this->json($notes, JsonResponse::HTTP_OK);
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'create_note_de_frais', methods: ['POST'])]
    public function createNoteDeFrais(Collections $collection, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $inputDTO = new NoteDeFraisInputDTO($data['description'], (float) $data['montant'], $data['date'], $data['imageNdf'] ?? null);
        $this->createNoteDeFraisUseCase->execute($collection, $inputDTO);

        return $this->json(['success' => 'Note de frais created'], JsonResponse::HTTP_CREATED);
    }

    #[Route('/api/notes-de-frais/{id}', name: 'update_note_de_frais', methods: ['PUT'])]
    public function updateNoteDeFrais(NoteDeFrais $note, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $inputDTO = new NoteDeFraisInputDTO($data['description'], (float) $data['montant'], $data['date'], $data['imageNdf'] ?? null);
        $this->updateNoteDeFraisUseCase->execute($note, $inputDTO);

        return $this->json(['success' => 'Note de frais updated'], JsonResponse::HTTP_OK);
    }

    #[Route('/api/notes-de-frais/{id}', name: 'delete_note_de_frais', methods: ['DELETE'])]
    public function deleteNoteDeFrais(NoteDeFrais $note): JsonResponse
    {
        $this->deleteNoteDeFraisUseCase->execute($note);
        return $this->json(['success' => 'Note de frais deleted'], JsonResponse::HTTP_NO_CONTENT);
    }
}
