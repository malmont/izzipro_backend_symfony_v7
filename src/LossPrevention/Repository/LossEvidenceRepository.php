<?php

namespace App\LossPrevention\Repository;

use Doctrine\ORM\EntityRepository;

/**
 * Preuves des constats : ajout seulement (la base refuse toute modification), une par (constat, source).
 *
 * @extends EntityRepository<\App\LossPrevention\Entity\LossEvidence>
 */
class LossEvidenceRepository extends EntityRepository
{
    /**
     * @param array<string, mixed> $snapshot copie figée : nombres, codes, dates, identifiants ; jamais un nom ni un courriel
     * @return bool vrai si la preuve a été ajoutée, faux si elle y était déjà (analyse rejouée)
     */
    public function add(int $findingId, string $kind, \DateTimeImmutable $at, string $refType, int $refId, array $snapshot, ?int $auditSequence = null, ?string $auditHash = null): bool
    {
        $json = self::canonical($snapshot);

        return $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO loss_evidence (finding_id, kind, occurred_at, ref_type, ref_id, audit_sequence, audit_hash, snapshot, fingerprint, created_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (finding_id, ref_type, ref_id) DO NOTHING',
            [$findingId, $kind, $at->format('Y-m-d H:i:s'), $refType, $refId, $auditSequence, $auditHash, $json, hash('sha256', $json), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        ) === 1;
    }

    /** @return list<array<string, mixed>> preuves d'un constat dans l'ordre du temps */
    public function forFinding(int $findingId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT id, kind, occurred_at, ref_type, ref_id, audit_sequence, audit_hash, snapshot, fingerprint FROM loss_evidence WHERE finding_id = ? ORDER BY occurred_at, id', [$findingId]);
    }

    /** JSON canonique (clés triées, sans espace) : la même copie donne toujours la même empreinte */
    public static function canonical(array $data): string
    {
        $sort = function (mixed $v) use (&$sort): mixed {
            if (!is_array($v)) {
                return $v;
            }
            if (!array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return (string) json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
