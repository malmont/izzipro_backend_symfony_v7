<?php

namespace App\LossPrevention\Services;

use App\Services\CaisseService\StoreClock;
use Doctrine\DBAL\Connection;

/**
 * Ce qu'une règle reçoit pendant un passage de l'analyse : la connexion du site (lecture de la caisse), l'horloge du magasin, la devise,
 * l'écart de caisse toléré, ses réglages en vigueur et l'écrivain des constats.
 */
final class LossAnalysisContext
{
    /**
     * @param array<string, array{enabled: bool, params: array<string, int>, personEnabled: bool, personParams: array<string, int>}> $rules par code
     */
    public function __construct(
        public readonly Connection $conn,
        public readonly StoreClock $clock,
        public readonly string $currency,
        public readonly int $discrepancyThreshold,
        public readonly array $rules,
        public readonly LossFindingWriter $writer,
        private readonly ?int $backfillUntil,
        /** Aucune entrée du journal de caisse de séquence inférieure ou égale n'est lue (notice aux salariés, choix A) ; 0 = pas de plancher */
        public readonly int $floorSequence = 0,
    ) {
    }

    /** Une entrée du journal antérieure à la première analyse : son constat est une « reprise » */
    public function isBackfill(int $sequence): bool
    {
        return $this->backfillUntil !== null && $sequence <= $this->backfillUntil;
    }
}
