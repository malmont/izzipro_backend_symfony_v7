<?php

namespace App\Services\GestionService;

use Symfony\Component\HttpFoundation\Request;

/**
 * Filtres et pagination facultative des listes de gestion (lot G5, 10/11/2026) : `?q=` (texte), `?status=` (collections :
 * open | closed | deleted | all ; défaut : ouvertes et fermées), `?page=&limit=` (limit ≤ 100). Sans `page` ni `limit`, la
 * liste reste complète et le corps inchangé ; avec, le corps est la page et les en-têtes `X-Total-Count`, `X-Page`,
 * `X-Limit` donnent le total (même convention que `GET /api/ordersuser`).
 */
final class GestionListQuery
{
    public const STATUSES = ['open', 'closed', 'deleted', 'all'];

    public function __construct(
        public readonly ?string $q,
        public readonly ?string $status,
        public readonly ?int $page,
        public readonly ?int $limit,
    ) {
    }

    public static function fromRequest(Request $request, bool $withStatus = false): self
    {
        $q = trim((string) $request->query->get('q', ''));
        $status = $withStatus ? strtolower(trim((string) $request->query->get('status', ''))) : '';
        if ($status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new GestionValidationException(['status' => 'INVALID_VALUE'], 'status : open, closed, deleted ou all attendu');
        }
        $paged = $request->query->has('page') || $request->query->has('limit');

        return new self(
            $q !== '' ? mb_substr($q, 0, 100) : null,
            $status !== '' ? $status : null,
            $paged ? max(1, $request->query->getInt('page', 1)) : null,
            $paged ? min(100, max(1, $request->query->getInt('limit', 20))) : null,
        );
    }

    public function isPaged(): bool
    {
        return $this->page !== null;
    }

    /** Suffixe de clé de cache : vide pour la liste complète par défaut */
    public function cacheSuffix(): string
    {
        if ($this->q === null && $this->status === null && !$this->isPaged()) {
            return '';
        }

        return '_' . ($this->status ?? 'default') . '_' . md5((string) $this->q) . '_' . ($this->page ?? 0) . '_' . ($this->limit ?? 0);
    }

    /** @return array<string, string> en-têtes de pagination (vides sans pagination) */
    public function headers(int $total): array
    {
        return $this->isPaged() ? ['X-Total-Count' => (string) $total, 'X-Page' => (string) $this->page, 'X-Limit' => (string) $this->limit] : [];
    }
}
