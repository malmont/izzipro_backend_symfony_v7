<?php

namespace App\Services\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Lot PERF-PAGINATION (18/11/2026, contrat validé par le chef) : la page demandée d'une liste (`?page=&limit=`), FACULTATIVE. Sans `page`, rien
 * (null) : la route rend sa liste entière, comme avant. Avec : `page` ≥ 1, `limit` de 1 à 200 (50 par défaut) ; une valeur hors bornes est
 * ramenée aux bornes, sans erreur. En-têtes `X-Total-Count`, `X-Page`, `X-Limit` (exposés au navigateur par nginx). Avec OFFSET, une ligne
 * créée entre deux pages décale la suite : une ligne peut se répéter ou manquer (un curseur viendra si le besoin en vient).
 */
final class ListPage
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    private function __construct(public readonly int $page, public readonly int $limit)
    {
    }

    public static function fromRequest(Request $request): ?self
    {
        $page = $request->query->get('page');
        if ($page === null || $page === '' || is_array($page)) {
            return null;
        }
        $limit = $request->query->get('limit');
        $limit = is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;

        return new self(max(1, is_numeric($page) ? (int) $page : 1), max(1, min(self::MAX_LIMIT, $limit)));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /** @return array<string, string> */
    public function headers(int $total): array
    {
        return ['X-Total-Count' => (string) $total, 'X-Page' => (string) $this->page, 'X-Limit' => (string) $this->limit];
    }
}
