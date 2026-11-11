<?php

namespace App\Gestion\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Vignettes d'accueil écartées (`docs/vignettes-gestion.md`) : lecture par utilisateur, écriture idempotente (une ligne par
 * utilisateur et par vignette, remplacée à chaque nouvel écart).
 */
class GestionTileDismissalRepository extends EntityRepository
{
    /** @return array<string, array{signature: ?string, until: ?string}> vignette → écart en cours */
    public function forUser(int $userId): array
    {
        $out = [];
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT tile_id, signature, until FROM gestion_tile_dismissal WHERE user_id = ?', [$userId]);
        foreach ($rows as $r) {
            $out[(string) $r['tile_id']] = ['signature' => $r['signature'], 'until' => $r['until']];
        }

        return $out;
    }

    public function dismiss(int $userId, string $tileId, ?string $signature, ?\DateTimeImmutable $until): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO gestion_tile_dismissal (user_id, tile_id, signature, dismissed_at, until) VALUES (:u, :t, :s, NOW(), :until)'
            . ' ON CONFLICT (user_id, tile_id) DO UPDATE SET signature = EXCLUDED.signature, dismissed_at = EXCLUDED.dismissed_at, until = EXCLUDED.until',
            ['u' => $userId, 't' => $tileId, 's' => $signature, 'until' => $until?->format('Y-m-d H:i:s')]
        );
    }
}
