<?php

namespace App\Gestion\UseCase;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\HomeTilesService;

/** Vignettes d'accueil de la gestion (`docs/vignettes-gestion.md`) : liste pour l'utilisateur connecté, écart d'une vignette. */
class HomeTilesUseCase
{
    public function __construct(private readonly HomeTilesService $tiles)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(int $userId, string $locale): array
    {
        return $this->tiles->visible($userId, $locale);
    }

    public function dismiss(int $userId, string $tileId): void
    {
        if (!in_array($tileId, HomeTilesService::KNOWN, true)) {
            throw new GestionConflictException('TILE_NOT_FOUND', 'Vignette inconnue.', 404, ['tileId' => $tileId]);
        }
        $this->tiles->dismiss($userId, $tileId);
    }
}
