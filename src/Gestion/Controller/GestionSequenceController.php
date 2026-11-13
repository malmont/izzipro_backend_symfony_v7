<?php

namespace App\Gestion\Controller;

use App\Gestion\Services\GestionListQuery;
use App\Gestion\UseCase\GestionSequenceUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guide des séquences de l'application de gestion (lot IA-SEQ, 13/11/2026, `docs/sequences-gestion.md`), `ROLE_ADMIN` (`access_control`
 * `/api/gestion`), module de gestion actif. Lecture seule : aucune écriture, aucun gel, aucun cache propre.
 * `?locale=en` traduit ; toute autre valeur rend le français, comme le cheminement. 404 `SEQUENCE_NOT_FOUND`, `INSTANCE_NOT_FOUND` ;
 * 422 `VALIDATION_FAILED` (`GestionValidationExceptionListener`).
 */
class GestionSequenceController extends AbstractController
{
    public function __construct(private readonly GestionSequenceUseCase $useCase)
    {
    }

    /** → {sequences: [{key, kind, title, description, translated, status, progress, next, instances}]} */
    #[Route('/api/gestion/sequences', name: 'gestion_sequences', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->json($this->useCase->list(self::locale($request)));
    }

    /** → {key, kind, title, description, translated, status, progress, next, instance, steps} ; ?instance= : sans lui, l'instance courante */
    #[Route('/api/gestion/sequences/{key}', name: 'gestion_sequence', methods: ['GET'], requirements: ['key' => '[a-z_]+(\.[a-z_]+)+'])]
    public function detail(string $key, Request $request): JsonResponse
    {
        $instance = $request->query->get('instance');

        return $this->json($this->useCase->detail($key, is_string($instance) ? $instance : null, self::locale($request)));
    }

    /**
     * Articles qu'une étape du guide ouvre : `?filter=missing-cost` (sans coût d'achat, règle de `MissingCostQuery`), `&from=&to=` (jours
     * du magasin : seulement ceux vendus dans le créneau), `&q=`, `&page=&limit=` (limit ≤ 100). → [{id, name, code, image, price, stock,
     * commandeId, periodId, costSource}] ; en-tête `X-Total-Count` toujours, `X-Page` et `X-Limit` avec la pagination.
     */
    #[Route('/api/gestion/articles', name: 'gestion_articles', methods: ['GET'])]
    public function articles(Request $request): JsonResponse
    {
        $query = GestionListQuery::fromRequest($request);
        $text = fn (string $name): ?string => is_string($request->query->get($name)) ? trim((string) $request->query->get($name)) : null;
        $page = $this->useCase->articles($text('filter'), $text('from'), $text('to'), $query);

        return $this->json($page['items'], JsonResponse::HTTP_OK, ['X-Total-Count' => (string) $page['total']] + $query->headers($page['total']));
    }

    private static function locale(Request $request): string
    {
        $asked = $request->query->get('locale');

        return is_string($asked) && strtolower($asked) === 'en' ? 'en' : 'fr';
    }
}
