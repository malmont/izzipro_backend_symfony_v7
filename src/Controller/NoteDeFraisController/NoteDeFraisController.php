<?php

namespace App\Controller\NoteDeFraisController;

use App\Entity\NoteDeFrais;
use App\Entity\Collections;
use App\Dto\NoteDeFraisInputDTO;
use App\UseCase\NoteDeFraisUseCase\GetNotesDeFraisByCollectionUseCase;
use App\UseCase\NoteDeFraisUseCase\CreateNoteDeFraisUseCase;
use App\UseCase\NoteDeFraisUseCase\UpdateNoteDeFraisUseCase;
use App\UseCase\NoteDeFraisUseCase\DeleteNoteDeFraisUseCase;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;
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
    private TenantCacheService $cache;

    public function __construct(
        GetNotesDeFraisByCollectionUseCase $getNotesDeFraisByCollectionUseCase,
        CreateNoteDeFraisUseCase $createNoteDeFraisUseCase,
        UpdateNoteDeFraisUseCase $updateNoteDeFraisUseCase,
        DeleteNoteDeFraisUseCase $deleteNoteDeFraisUseCase,
        TenantCacheService $cache,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null // devise du site (lot G4, 09/11/2026)
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
            fn () => $this->getNotesDeFraisByCollectionUseCase->execute($collection, $host),
            3600,
            [...CacheTags::itemTags(CacheTags::COLLECTIONS, $collection->getId()), ...CacheTags::listTags(CacheTags::NOTES_DE_FRAIS)]
        );

        return new JsonResponse($notes, JsonResponse::HTTP_OK);
    }

    #[Route('/api/collections/{id}/notes-de-frais', name: 'create_note_de_frais', methods: ['POST'])]
    public function createNoteDeFrais(Collections $collection, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST); // lot G4 (09/11/2026)
        }

        $input = new \App\Services\GestionService\GestionInput($data);
        $inputDTO = new NoteDeFraisInputDTO(
            (string) $input->text('description', false, 255),
            (int) $input->cents('montant'),
            (string) $input->date('date')?->format('Y-m-d'),
            (int) $input->id('typeNoteDeFraisId')
        );
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)

        $note = $this->createNoteDeFraisUseCase->execute($collection, $inputDTO);
        // 201 avec l'objet créé (lot G4, 09/11/2026 ; avant : {"success"})
        return $this->json(new \App\Dto\NoteDeFraisOutputDTO($note, $this->host($request), $this->currencyCode()), JsonResponse::HTTP_CREATED);
    }

    /** Écriture : administrateur ou propriétaire de la collection de la note (S2.7, 30/10/2026 : avant, tout utilisateur connecté, toute note) */
    #[Route('/api/notes-de-frais/{id}', name: 'update_note_de_frais', methods: ['PUT'])]
    public function updateNoteDeFrais(NoteDeFrais $note, Request $request): JsonResponse
    {
        if (($refused = $this->refuseUnlessOwnerOrAdmin($note)) !== null) {
            return $refused;
        }
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST); // lot G4 (09/11/2026)
        }

        $input = new \App\Services\GestionService\GestionInput($data);
        $inputDTO = new NoteDeFraisInputDTO(
            (string) $input->text('description', false, 255),
            (int) $input->cents('montant'),
            (string) $input->date('date')?->format('Y-m-d'),
            (int) $input->id('typeNoteDeFraisId')
        );
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)

        $note = $this->updateNoteDeFraisUseCase->execute($note, $inputDTO);
        return $this->json(new \App\Dto\NoteDeFraisOutputDTO($note, $this->host($request), $this->currencyCode()), JsonResponse::HTTP_OK); // 200 avec l'objet (lot G4)
    }

    #[Route('/api/notes-de-frais/{id}', name: 'delete_note_de_frais', methods: ['DELETE'])]
    public function deleteNoteDeFrais(NoteDeFrais $note): \Symfony\Component\HttpFoundation\Response
    {
        if (($refused = $this->refuseUnlessOwnerOrAdmin($note)) !== null) {
            return $refused;
        }
        $this->deleteNoteDeFraisUseCase->execute($note);
        return new \Symfony\Component\HttpFoundation\Response('', \Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT); // 204 sans corps (lot G4)
    }

    /** 403 FORBIDDEN sauf administrateur ou propriétaire de la collection de la note (la note elle-même n'a pas de propriétaire) */
    private function refuseUnlessOwnerOrAdmin(NoteDeFrais $note): ?JsonResponse
    {
        if ($this->isGranted('ROLE_ADMIN') || ($note->getCollection()?->getUserCollections() !== null && $note->getCollection()->getUserCollections() === $this->getUser())) {
            return null;
        }

        return new JsonResponse(['error' => 'FORBIDDEN', 'message' => 'Cette note de frais ne vous appartient pas'], JsonResponse::HTTP_FORBIDDEN);
    }

    /** Même hôte que la liste GET (images des types de note de frais sous /assets/images/) */
    private function host(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    private function currencyCode(): string
    {
        return $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT;
    }
}
