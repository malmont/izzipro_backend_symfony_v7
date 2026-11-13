<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionListQuery;
use App\Gestion\Services\GestionSequenceService;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\MissingCostQuery;

/**
 * Guide des séquences (lot IA-SEQ, 13/11/2026, `docs/sequences-gestion.md`) : liste, détail, et la liste des articles qu'une étape
 * « article sans coût » ouvre. Lecture seule.
 */
class GestionSequenceUseCase
{
    public const ARTICLE_FILTERS = ['missing-cost'];

    public function __construct(private readonly GestionSequenceService $sequences, private readonly MissingCostQuery $missingCost)
    {
    }

    /** @return array{sequences: list<array<string, mixed>>} */
    public function list(string $locale): array
    {
        return $this->sequences->list($locale);
    }

    /** @return array<string, mixed> */
    public function detail(string $key, ?string $instance, string $locale): array
    {
        return $this->sequences->detail($key, $instance, $locale);
    }

    /**
     * GET /api/gestion/articles?filter=missing-cost[&from=&to=] : 422 `VALIDATION_FAILED` pour un filtre absent ou inconnu, ou un créneau
     * incomplet ou mal formé.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function articles(?string $filter, ?string $from, ?string $to, GestionListQuery $query): array
    {
        if (!in_array($filter, self::ARTICLE_FILTERS, true)) {
            throw new GestionValidationException(['filter' => $filter === null || $filter === '' ? 'REQUIRED' : 'INVALID_VALUE'], 'filter : missing-cost attendu');
        }
        // un créneau incomplet ou mal formé est refusé par la règle elle-même (MissingCostQuery)

        return $this->missingCost->page($query->q, $from === '' ? null : $from, $to === '' ? null : $to, $query->limit, $query->isPaged() ? ($query->page - 1) * $query->limit : 0);
    }
}
