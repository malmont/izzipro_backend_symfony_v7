<?php

namespace App\Controller\GestionController;

use App\Cache\CacheTags;
use App\Dto\CollectionOutputDTO;
use App\Entity\Collections;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\GestionService\GestionInput;
use App\Services\GestionService\GestionListQuery;
use App\Services\GestionService\PeriodCalendar;
use App\Services\GestionService\PeriodGuard;
use App\Services\TenantCacheService;
use App\UseCase\CollectionUseCase\GetCollectionsUseCase;
use App\UseCase\CollectionUseCase\UpdateCollectionUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Périodes de gestion (lot P1, 10/11/2026, `docs/periodes-gestion.md`), `ROLE_ADMIN` : liste (`GET /api/periodes` = collections de
 * `kind` period, créées à la lecture, filtres et pagination de G5), période courante, budget (`PATCH /api/periodes/{id}`, 409
 * `PERIOD_CLOSED` si fermée). Une période ne se supprime pas et ne se renomme pas (calendrier).
 */
class PeriodeController extends AbstractController
{
    public function __construct(
        private readonly GetCollectionsUseCase $collections,
        private readonly UpdateCollectionUseCase $update,
        private readonly PeriodCalendar $calendar,
        private readonly TenantCacheService $cache,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    #[Route('/api/periodes', name: 'gestion_periodes', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $base = GestionListQuery::fromRequest($request, true);
        $query = new GestionListQuery($base->q, $base->status, $base->page, $base->limit, Collections::KIND_PERIOD);
        $host = $request->getSchemeAndHttpHost();
        $result = $this->cache->get(
            'collections_all' . $query->cacheSuffix(),
            fn () => $this->collections->execute($host, $query),
            600,
            [...CacheTags::listTags(CacheTags::COLLECTIONS), ...CacheTags::listTags(CacheTags::COMMANDES)]
        );

        return new JsonResponse($result['items'], JsonResponse::HTTP_OK, $query->headers($result['total']));
    }

    #[Route('/api/periodes/courante', name: 'gestion_periode_courante', methods: ['GET'])]
    public function current(Request $request): JsonResponse
    {
        return $this->json($this->dto($this->calendar->current(), $request));
    }

    #[Route('/api/periodes/{id}', name: 'gestion_periode_patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(Collections $collection, Request $request, PeriodGuard $guard): JsonResponse
    {
        if (!$collection->isPeriod()) {
            return $this->json(['error' => 'NOT_A_PERIOD', 'code' => 'NOT_A_PERIOD', 'message' => 'Cette collection est manuelle : PATCH /api/collections/{id}'], JsonResponse::HTTP_NOT_FOUND);
        }
        $data = json_decode((string) $request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'INVALID_JSON', 'code' => 'INVALID_JSON', 'message' => 'Corps JSON invalide'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $input = new GestionInput($data);
        $fields = [];
        if (array_key_exists('budget', $data)) {
            $fields['budget'] = $input->cents('budget');
        }
        $input->check();

        return $this->json($this->dto($this->update->execute($collection, $fields, $guard), $request));
    }

    private function dto(Collections $collection, Request $request): CollectionOutputDTO
    {
        return new CollectionOutputDTO($collection, $request->getSchemeAndHttpHost(), $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT, $this->calendar);
    }
}
