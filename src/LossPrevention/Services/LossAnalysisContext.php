<?php

namespace App\LossPrevention\Services;

use App\Services\CaisseService\StoreClock;
use Doctrine\DBAL\Connection;

/**
 * Ce qu'une règle reçoit pendant un passage de l'analyse : la connexion du site (lecture de la caisse), l'horloge du magasin, la devise,
 * l'écart de caisse toléré, ses réglages en vigueur et le puits des constats (l'écrivain en base, ou la mémoire pour un aperçu).
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
        public readonly LossFindingSink $writer,
        private readonly ?int $backfillUntil,
        /** Aucune entrée du journal de caisse de séquence inférieure ou égale n'est lue (notice aux salariés, choix A) ; 0 = pas de plancher */
        public readonly int $floorSequence = 0,
    ) {
    }

    private ?string $floorAt = null;
    private bool $floorRead = false;

    /**
     * Un acte daté (UTC, « Y-m-d H:i:s ») précède-t-il le plancher de la notice aux salariés ? Sert aux règles qui relisent, pour un acte
     * d'après la notice, une vente d'ORIGINE qui peut la précéder (retour, annulation) : le vendeur de cette vente n'est alors pas nommé
     * (choix C : personne n'est désigné pour un acte d'avant l'information des salariés). Sans plancher : jamais. Plancher posé mais entrée
     * introuvable : dans le doute, oui.
     */
    public function beforeFloor(?string $utc): bool
    {
        if ($this->floorSequence <= 0 || $utc === null) {
            return false;
        }
        if (!$this->floorRead) {
            $at = $this->conn->fetchOne('SELECT occurred_at FROM pos_audit_log WHERE sequence = ?', [$this->floorSequence]);
            $this->floorAt = $at === false || $at === null ? null : substr((string) $at, 0, 19);
            $this->floorRead = true;
        }

        return $this->floorAt === null || substr($utc, 0, 19) <= $this->floorAt;
    }

    /** Une entrée du journal antérieure à la première analyse : son constat est une « reprise » */
    public function isBackfill(int $sequence): bool
    {
        return $this->backfillUntil !== null && $sequence <= $this->backfillUntil;
    }
}
