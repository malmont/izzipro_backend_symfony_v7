<?php

namespace App\Controller\CollectionsController;

use App\Dto\CollectionInputDTO;
use App\Dto\CollectionOutputDTO;
use App\UseCase\CollectionUseCase\CreateCollectionUseCase;
use App\UseCase\CollectionUseCase\GetCollectionsUseCase;
use App\UseCase\CollectionUseCase\DeleteCollectionUseCase;
use App\Entity\Collections;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class CollectionController extends AbstractController
{
    private CreateCollectionUseCase $createCollectionUseCase;
    private GetCollectionsUseCase $getCollectionsUseCase;
    private DeleteCollectionUseCase $deleteCollectionUseCase;
    private TenantCacheService $cache;

    public function __construct(
        CreateCollectionUseCase $createCollectionUseCase,
        GetCollectionsUseCase $getCollectionsUseCase,
        DeleteCollectionUseCase $deleteCollectionUseCase,
        TenantCacheService $cache,
        private readonly ?\App\UseCase\CollectionUseCase\UpdateCollectionUseCase $updateCollectionUseCase = null, // lot P1 (10/11/2026)
        private readonly ?\App\Gestion\Services\PeriodGuard $periodGuard = null,
        private readonly ?\App\Gestion\Services\PeriodCalendar $calendar = null,
        private readonly ?\App\Services\BoutiqueSettingsService\TenantCurrencyProvider $currency = null // lot G4 (09/11/2026)
    ) {
        $this->createCollectionUseCase = $createCollectionUseCase;
        $this->getCollectionsUseCase = $getCollectionsUseCase;
        $this->deleteCollectionUseCase = $deleteCollectionUseCase;
        $this->cache = $cache;
    }

    #[Route('/api/createcollections', name: 'create_collection', methods: ['POST'])]
    public function createCollection(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$this->getUser()) {
            return new JsonResponse(['error' => 'Authentification requise', 'code' => 'UNAUTHENTICATED', 'message' => 'Authentification requise'], Response::HTTP_UNAUTHORIZED);
        }

        $input = new \App\Gestion\Services\GestionInput(is_array($data) ? $data : []);
        $budget = $input->cents('budgetCollection');
        $name = $input->text('nomCollection');
        $input->date('startDateCollection', false);
        $input->date('endDateCollection', false);
        $input->check(); // 422 VALIDATION_FAILED (lot G1, 09/11/2026)
        // propriétaire = utilisateur du jeton (le userId du corps est ignoré) ; 201 avec l'objet créé (lot G4, 09/11/2026)
        $inputDTO = new CollectionInputDTO(['budgetCollection' => $budget, 'nomCollection' => $name, 'userId' => $this->getUser()->getId()] + $data);
        $collection = $this->createCollectionUseCase->execute($inputDTO);
        return $this->json(new CollectionOutputDTO($collection, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT, $this->calendar), Response::HTTP_CREATED);
    }

    #[Route('/api/collections', name: 'get_collections', methods: ['GET'])]
    public function getCollections(Request $request): JsonResponse
    {
        $host = $request->getSchemeAndHttpHost();
        $query = \App\Gestion\Services\GestionListQuery::fromRequest($request, true); // ?status=&q=&page=&limit= (lot G5, 10/11/2026)
        $cacheKey = 'collections_all' . $query->cacheSuffix();
        // une collection est listée avec ses commandes fournisseurs : elle suit aussi leurs écritures
        $result = $this->cache->get(
            $cacheKey,
            fn () => $this->getCollectionsUseCase->execute($host, $query),
            600,
            [...CacheTags::listTags(CacheTags::COLLECTIONS), ...CacheTags::listTags(CacheTags::COMMANDES)]
        );
        return new JsonResponse($result['items'], JsonResponse::HTTP_OK, $query->headers($result['total']));
    }

    #[Route('/api/collections/{id}', name: 'delete_collection', methods: ['DELETE'])]
    public function deleteCollection(Collections $collection): \Symfony\Component\HttpFoundation\Response
    {
        $this->deleteCollectionUseCase->execute($collection); // suppression logique
        return new \Symfony\Component\HttpFoundation\Response('', \Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT); // 204 sans corps (lot G4, 09/11/2026 ; avant : 200 avec message)
    }

    /** Budget (cents) et nom d'une collection manuelle (lot P1, 10/11/2026) ; 409 COLLECTION_CLOSED / PERIOD_CLOSED si fermée ; une période se règle par PATCH /api/periodes/{id} */
    #[Route('/api/collections/{id}', name: 'patch_collection', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patchCollection(Collections $collection, Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $input = new \App\Gestion\Services\GestionInput($data);
        $fields = [];
        if (array_key_exists('budget', $data)) {
            $fields['budget'] = $input->cents('budget');
        }
        if (array_key_exists('nomCollection', $data)) {
            $fields['nomCollection'] = $input->text('nomCollection');
        }
        $input->check();
        $collection = $this->updateCollectionUseCase->execute($collection, $fields, $this->periodGuard);

        return $this->json(new CollectionOutputDTO($collection, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? \App\Services\BoutiqueSettingsService\TenantCurrencyProvider::DEFAULT, $this->calendar), JsonResponse::HTTP_OK);
    }
}
